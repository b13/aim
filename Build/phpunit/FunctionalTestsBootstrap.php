<?php

declare(strict_types=1);

/*
 * Bootstrap for aim functional tests.
 *
 * Defines ORIGINAL_ROOT (required by FunctionalTestCase) and
 * loads the Composer autoloader. The testing framework handles
 * the full TYPO3 instance setup per test class.
 */

require dirname(__DIR__, 2) . '/.Build/vendor/autoload.php';

$testbase = new \TYPO3\TestingFramework\Core\Testbase();
$testbase->defineSitePath();
$testbase->defineOriginalRootPath();