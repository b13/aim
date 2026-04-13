<?php

$finder = PhpCsFixer\Finder::create()
    ->in('Classes')
    ->in('Configuration');

return \TYPO3\CodingStandards\CsFixerConfig::create()
    ->setUsingCache(false)
    ->setFinder($finder);
