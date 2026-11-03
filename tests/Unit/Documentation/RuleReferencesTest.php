<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

/**
 * Every rule reference such as "(R12.9)" or "R3.3–R3.14" in code, tests and
 * documentation must point to a rule that docs/domain-logic.md defines –
 * otherwise a reader finds nothing behind the number. A rule counts as
 * defined if the document names it, also inside a range, or if it is a
 * whole section ("## R12 – …").
 */
final class RuleReferencesTest extends TestCase
{
    private const SCANNED = ['src', 'tests', 'public', 'bin', 'config', 'deploy', 'db', 'docs', 'lang', '.claude'];

    private const EXTENSIONS = ['php', 'md', 'sql', 'sh', 'js', 'css', 'example'];

    /** "R12", "R12.9", "R3.3–R3.14" or "R3.3–3.14"; not part of a longer word such as "HR2". */
    private const REFERENCE = '/(?<![\p{L}\p{N}_])R(\d+)(?:\.(\d+))?(?:\s?–\s?R?(\d+)\.(\d+))?(?![\p{L}\p{N}_]|\.\d)/u';

    public function testEveryReferencedRuleIsDefined(): void
    {
        $root = dirname(__DIR__, 3);
        $document = $root . '/docs/domain-logic.md';
        $defined = $this->rules((string) file_get_contents($document));
        preg_match_all('/^## R(\d+)\b/mu', (string) file_get_contents($document), $sections);
        foreach ($sections[1] as $section) {
            $defined[$section] = true;
        }

        $findings = [];
        foreach ($this->files($root) as $pathName) {
            if ($pathName === $document) {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents($pathName)) as $index => $line) {
                foreach (array_keys($this->rules($line)) as $rule) {
                    if (! isset($defined[$rule])) {
                        $findings[] = sprintf('R%s in %s:%d', $rule, substr($pathName, strlen($root) + 1), $index + 1);
                    }
                }
            }
        }

        self::assertSame([], $findings, "Rule references without a rule in docs/domain-logic.md:\n" . implode("\n", $findings));
    }

    /**
     * @return array<string, true> rule numbers like "12" or "12.9", ranges expanded
     */
    private function rules(string $text): array
    {
        preg_match_all(self::REFERENCE, $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $rules = [];
        foreach ($matches as $match) {
            [, $section, $rule, $toSection, $toRule] = $match + [null, null, null, null, null];
            if ($rule === null) {
                $rules[$section] = true;
                continue;
            }
            $last = $toRule !== null && $toSection === $section ? (int) $toRule : (int) $rule;
            for ($number = (int) $rule; $number <= $last; $number++) {
                $rules[$section . '.' . $number] = true;
            }
        }
        return $rules;
    }

    /**
     * @return list<string>
     */
    private function files(string $root): array
    {
        $files = [$root . '/README.md', $root . '/AGENTS.md', $root . '/SECURITY.md'];
        foreach (self::SCANNED as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }
        return $files;
    }
}
