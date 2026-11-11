<?php

declare(strict_types=1);

namespace BCC\Core\Admin\Tests;

use BCC\Core\Admin\ApiKeysPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-52: the API Keys page must show a defined secret's LENGTH only — no
 * character of the value (the old preview leaked its first 4 + last 4).
 */
#[CoversClass(ApiKeysPage::class)]
final class ApiKeysMaskTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function secrets(): iterable
    {
        yield 'long value' => ['test-fixture-not-a-real-credential-0123456789'];
        yield 'short key'  => ['abc123'];
        yield 'hex-like'   => ['deadbeefcafef00d0123456789abcdefdeadbeefcafef00d'];
    }

    #[DataProvider('secrets')]
    public function testShowsLengthAndNoCharacterOfTheSecret(string $secret): void
    {
        $shown = ApiKeysPage::mask($secret);

        self::assertStringContainsString('(' . strlen($secret) . ' chars)', $shown);

        // No 3-character run of the secret appears in what is shown.
        for ($i = 0; $i + 3 <= strlen($secret); $i++) {
            self::assertStringNotContainsString(substr($secret, $i, 3), $shown);
        }
        self::assertStringNotContainsString(substr($secret, 0, 4), $shown);
        self::assertStringNotContainsString(substr($secret, -4), $shown);
    }
}
