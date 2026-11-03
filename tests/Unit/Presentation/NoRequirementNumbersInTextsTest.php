<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use PHPUnit\Framework\TestCase;

/**
 * Requirement numbers like "(R12.7)" belong in comments, not in texts that
 * visitors or administrators get to see. All string literals in `src/`,
 * `public/index.php` and the text catalogs `lang/` are checked; comments are
 * left out.
 */
final class NoRequirementNumbersInTextsTest extends TestCase
{
    public function testNoRequirementNumbersInStrings(): void
    {
        $root  = dirname(__DIR__, 3);
        $files = [$root . '/public/index.php', ...glob($root . '/lang/*.php')];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $findings = [];
        foreach ($files as $pathName) {
            foreach (\PhpToken::tokenize((string) file_get_contents($pathName)) as $token) {
                $isText = $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML]);
                if ($isText && preg_match('/\(R\d+\.\d+/', $token->text) === 1) {
                    $findings[] = sprintf('%s:%d', substr($pathName, strlen($root) + 1), $token->line);
                }
            }
        }

        self::assertSame([], $findings, 'Requirement-Nummern in sichtbaren Texten: ' . implode(', ', $findings));
    }
}
