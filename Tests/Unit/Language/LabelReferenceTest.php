<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Language;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A label key that is referenced but not defined renders as the raw
 * "LLL:EXT:..." string in the backend, or as nothing at all, and nothing else
 * in this suite notices. Only literal references are checked: a key composed
 * at runtime never matches the pattern, so the dynamic prefixes the modules
 * build raise no false alarms.
 *
 * The inverse, English written straight into a template instead of going
 * through a label, is not detectable this way and still needs reading.
 */
final class LabelReferenceTest extends TestCase
{
    #[Test]
    public function everyLiterallyReferencedLabelKeyExists(): void
    {
        $root = dirname(__DIR__, 3);
        $defined = [];
        foreach (glob($root . '/Resources/Private/Language/*.xlf') ?: [] as $path) {
            $document = new \DOMDocument();
            self::assertTrue($document->load($path), basename($path) . ' is not valid XML.');
            foreach ($document->getElementsByTagName('trans-unit') as $unit) {
                $defined[basename($path) . ':' . $unit->getAttribute('id')] = true;
            }
        }
        self::assertNotSame([], $defined, 'No label definitions were found at all.');

        $missing = [];
        foreach ($this->sourceFiles($root) as $file) {
            preg_match_all(
                '#LLL:EXT:aim/Resources/Private/Language/([A-Za-z_]+\.xlf):([A-Za-z0-9_.]+)#',
                (string)file_get_contents($file),
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $match) {
                // A trailing dot means the key is a prefix the code completes
                // at runtime, so there is nothing to look up here.
                if (str_ends_with($match[2], '.')) {
                    continue;
                }
                $reference = $match[1] . ':' . $match[2];
                if (!isset($defined[$reference])) {
                    $missing[$reference][] = substr($file, strlen($root) + 1);
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            "Referenced label keys that no XLF defines:\n" . implode("\n", array_map(
                static fn(string $key, array $files): string => '  ' . $key . ' (' . implode(', ', array_unique($files)) . ')',
                array_keys($missing),
                $missing,
            )),
        );
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(string $root): array
    {
        $files = [];
        foreach (['Classes', 'Configuration', 'Resources/Private'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'html', 'xlf'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        $files[] = $root . '/ext_localconf.php';

        return array_values(array_filter($files, 'is_file'));
    }
}
