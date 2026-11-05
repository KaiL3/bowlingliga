<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\I18n;

use Bowlingliga\I18n\Languages;
use Bowlingliga\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Every database operation named in `transactional('<key>', …)` has a name in
 * the catalogs (`operation.<key>`), so that a failed operation is reported in
 * the visitor's language (R4.3). The other catalogs are checked against the
 * default one by the CatalogTest.
 */
final class OperationNamesTest extends TestCase
{
    public function testEveryOperationHasANameInTheCatalog(): void
    {
        $root = dirname(__DIR__, 3);
        $catalog = Translator::catalog(Languages::DEFAULT);
        $missing = [];
        $found = 0;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/transactional\\('([^']*)'/", (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $key) {
                $found++;
                if (! isset($catalog["operation.{$key}"])) {
                    $missing[] = $key . ' in ' . basename($file->getPathname());
                }
            }
        }

        self::assertGreaterThan(0, $found);
        self::assertSame([], $missing, 'Operations without a name: ' . implode(', ', $missing));
    }
}
