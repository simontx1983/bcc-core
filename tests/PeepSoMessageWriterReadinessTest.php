<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoMessageWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * PeepSoMessageWriter::isReady() is the integration-boundary readiness
 * check the validator-message delivery worker + `vmq drain` use to tell an
 * unsupported execution context (WP-CLI, where PeepSo disables its Chat
 * module) apart from a genuine transient send failure.
 *
 * Readiness requires BOTH the writer's model (PeepSoMessagesModel) and the
 * user class the sender re-checks lean on (PeepSoUser) — both come from the
 * same PeepSo init, so "ready" means the full delivery + gate path can run.
 *
 * Each case runs in a separate process so eval-defining the stub PeepSo
 * classes in one test cannot leak into another.
 */
#[CoversClass(PeepSoMessageWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoMessageWriterReadinessTest extends TestCase
{
    public function testNotReadyWhenPeepSoAbsent(): void
    {
        self::assertFalse(class_exists('PeepSoMessagesModel', false));
        self::assertFalse(class_exists('PeepSoUser', false));
        self::assertFalse(PeepSoMessageWriter::isReady(), 'no PeepSo → unsupported context');
    }

    public function testNotReadyWhenOnlyModelPresent(): void
    {
        eval('class PeepSoMessagesModel {}');
        // PeepSoUser (needed by the sender ban re-check) is still absent.
        self::assertFalse(
            PeepSoMessageWriter::isReady(),
            'both PeepSo classes are required for readiness'
        );
    }

    public function testReadyWhenBothPeepSoClassesPresent(): void
    {
        eval('class PeepSoMessagesModel {}');
        eval('class PeepSoUser {}');
        self::assertTrue(PeepSoMessageWriter::isReady());
    }
}
