<?php

declare(strict_types=1);

namespace BCC\Core\Admin\Tests;

use BCC\Core\Admin\EnvBanner;
use BCC\Core\Rest\IdentityEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE BCC_ENV CONTRACT: ONE CONFIGURED VALUE, TWO CONSUMERS THAT MUST AGREE.
 *
 * ── THE DEFECT THIS FILE EXISTS TO PREVENT ──────────────────────────────
 * The banner used to accept 'prod'. The daily site-url-guard has always
 * compared the value the host REPORTS — verbatim, through
 * {@see IdentityEndpoint} — against the literal 'production'. Two
 * components, two vocabularies, and no value a production host could hold
 * that satisfied both:
 *
 *   BCC_ENV='prod'        banner OK,          guard FAILS daily
 *   BCC_ENV='production'  banner ENV UNKNOWN, guard OK
 *
 * Both hosts were consequently left on the literal 'Live Site', which
 * satisfied neither and made the banner useless as the confusion guard it
 * exists to be.
 *
 * 'production' is now canonical. 'prod' renders identically as a
 * compatibility alias so an un-migrated wp-config.php does not regress to
 * an unknown banner mid-rollout.
 *
 * ── EVERY TEST BELOW RUNS IN ITS OWN PROCESS ────────────────────────────
 * BCC_ENV is a CONSTANT. It cannot be redefined, so each case must define
 * it exactly once in a fresh interpreter — which is also the honest model
 * of production, where the value is fixed for the life of the request.
 */
#[CoversClass(EnvBanner::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EnvBannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/env-contract-stubs.php';
    }

    private function render(): string
    {
        ob_start();
        EnvBanner::render();

        return (string) ob_get_clean();
    }

    // ── The canonical value, and the alias that must match it ───────────

    public function testProductionRendersTheProductionBanner(): void
    {
        define('BCC_ENV', 'production');

        $html = $this->render();

        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('PROD', $html);
        self::assertStringNotContainsString('ENV UNKNOWN', $html);
    }

    public function testLegacyProdRendersTheProductionBannerToo(): void
    {
        define('BCC_ENV', 'prod');

        $html = $this->render();

        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('PROD', $html);
        self::assertStringNotContainsString('ENV UNKNOWN', $html);
    }

    /**
     * IDENTICAL, not merely both-recognised.
     *
     * Rendered in two separate processes because BCC_ENV can only be
     * defined once per interpreter, then compared byte-for-byte. If the
     * alias ever drifted to its own label or colour, an operator migrating
     * a host would see the banner change and reasonably conclude something
     * else had changed too.
     */
    public function testTheAliasRendersByteIdenticallyToTheCanonicalValue(): void
    {
        $render = static function (string $env): string {
            $php = <<<'PHP'
            <?php
            require %s;
            require %s;
            define('BCC_ENV', %s);
            ob_start();
            \BCC\Core\Admin\EnvBanner::render();
            echo ob_get_clean();
            PHP;

            $code = sprintf(
                $php,
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
                var_export(__DIR__ . '/Stubs/env-contract-stubs.php', true),
                var_export($env, true)
            );

            $tmp = tempnam(sys_get_temp_dir(), 'envbanner');
            self::assertIsString($tmp);
            file_put_contents($tmp, $code);
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp));
            unlink($tmp);

            return (string) $out;
        };

        $canonical = $render('production');
        $alias     = $render('prod');

        self::assertNotSame('', trim($canonical), 'the canonical render produced nothing');
        self::assertSame($canonical, $alias, 'the legacy alias must render byte-identically');
    }

    // ── The other recognised values are untouched ───────────────────────

    public function testStagingIsRecognised(): void
    {
        define('BCC_ENV', 'staging');

        $html = $this->render();

        self::assertStringContainsString('notice-warning', $html);
        self::assertStringContainsString('STAGING', $html);
        self::assertStringNotContainsString('ENV UNKNOWN', $html);
    }

    public function testDevIsRecognised(): void
    {
        define('BCC_ENV', 'dev');

        $html = $this->render();

        self::assertStringContainsString('notice-info', $html);
        self::assertStringContainsString('DEV', $html);
        self::assertStringNotContainsString('ENV UNKNOWN', $html);
    }

    public function testLocalIsRecognised(): void
    {
        define('BCC_ENV', 'local');

        $html = $this->render();

        self::assertStringContainsString('notice-info', $html);
        self::assertStringContainsString('LOCAL', $html);
        self::assertStringNotContainsString('ENV UNKNOWN', $html);
    }

    // ── Everything else stays loudly unknown ────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function unknownValues(): iterable
    {
        // The literal both hosts actually held. It is a human label, not a
        // token, and must never be guessed into meaning production.
        yield 'the value both hosts held' => ['Live Site'];

        // Case matters. 'Production' is somebody typing from memory, and
        // silently accepting it would make the guard's exact-match
        // comparison unpredictable.
        yield 'wrong case canonical' => ['Production'];
        yield 'wrong case alias'     => ['PROD'];
        yield 'wrong case staging'   => ['Staging'];

        yield 'empty'            => [''];
        yield 'whitespace'       => ['  '];
        yield 'padded canonical' => [' production '];
        yield 'plural'           => ['productions'];
        yield 'prefix'           => ['produ'];
        yield 'live'             => ['live'];
        yield 'arbitrary'        => ['banana'];
    }

    #[DataProvider('unknownValues')]
    public function testUnrecognisedValuesStayUnknown(string $env): void
    {
        define('BCC_ENV', $env);

        $html = $this->render();

        self::assertStringContainsString('ENV UNKNOWN', $html);
        self::assertStringContainsString('notice-warning', $html);
        self::assertStringNotContainsString('notice-error', $html);
    }

    public function testAnUndefinedConstantIsUnknown(): void
    {
        self::assertFalse(defined('BCC_ENV'));

        self::assertStringContainsString('ENV UNKNOWN', $this->render());
    }

    /** A non-string constant must not be coerced into a match. */
    public function testANonStringConstantIsUnknown(): void
    {
        define('BCC_ENV', 1);

        self::assertStringContainsString('ENV UNKNOWN', $this->render());
    }

    // ── The endpoint keeps reporting the RAW value ──────────────────────

    /**
     * The guard's entire purpose is to notice a host whose configuration
     * disagrees with what the caller believes. A value normalised on the
     * way out would hide exactly that disagreement — so the alias must live
     * in the display switch and nowhere else.
     */
    public function testTheIdentityEndpointReportsTheLegacyAliasVerbatim(): void
    {
        define('BCC_ENV', 'prod');

        $data = $this->identityPayload();

        self::assertSame('prod', $data['env'], 'the endpoint must not canonicalise the alias');
    }

    public function testTheIdentityEndpointReportsTheCanonicalValueVerbatim(): void
    {
        define('BCC_ENV', 'production');

        self::assertSame('production', $this->identityPayload()['env']);
    }

    /** Even a value the banner calls unknown is reported exactly as configured. */
    public function testTheIdentityEndpointReportsAnUnknownValueVerbatim(): void
    {
        define('BCC_ENV', 'Live Site');

        $data = $this->identityPayload();

        self::assertSame('Live Site', $data['env']);
        self::assertStringContainsString('ENV UNKNOWN', $this->render(), 'and the banner still refuses it');
    }

    public function testTheIdentityEndpointReportsUnknownWhenTheConstantIsAbsent(): void
    {
        self::assertFalse(defined('BCC_ENV'));

        self::assertSame('unknown', $this->identityPayload()['env']);
    }

    /** @return array<string, mixed> */
    private function identityPayload(): array
    {
        global $wpdb;
        $wpdb = new \BccEnvContractWpdb();

        $response = IdentityEndpoint::handle(new \WP_REST_Request());

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        return $data;
    }
}
