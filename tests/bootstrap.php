<?php
/**
 * PHPUnit bootstrap for bcc-core unit tests.
 *
 * Deliberately minimal — pure-unit, no WordPress core:
 *   - Defines ABSPATH so production files' `if (!defined('ABSPATH')) exit;`
 *     guard lets them parse when autoloaded.
 *   - Registers Composer's autoloader (PSR-4 `BCC\Core\` -> src/).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/../vendor/autoload.php';
