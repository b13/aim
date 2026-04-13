<?php

declare(strict_types=1);

/*
 * Bootstrap for aim unit tests.
 *
 * Loads the Composer autoloader which includes aim classes
 * via the PSR-4 mappings in composer.json (autoload + autoload-dev).
 */

require dirname(__DIR__, 2) . '/.Build/vendor/autoload.php';
