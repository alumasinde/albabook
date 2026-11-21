<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Cheap guards for non-negotiable rules (plan.md §30) that static analysis
 * does not catch on its own.
 */
final class ArchitectureTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return iterable<string> */
    private static function phpFiles(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    public function testMoneyCodeNeverUsesFloats(): void
    {
        foreach (self::phpFiles('app/Shared/Money') as $file) {
            $code = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/\bfloat\b|\(float\)|floatval\(|round\(|number_format\(/', $code, basename($file) . ' must stay integer-only (D-006).');
        }
    }

    public function testEveryPhpFileDeclaresStrictTypes(): void
    {
        foreach (['app', 'bootstrap', 'config', 'routes', 'tests'] as $dir) {
            foreach (self::phpFiles($dir) as $file) {
                self::assertStringContainsString('declare(strict_types=1);', (string) file_get_contents($file), $file);
            }
        }
    }

    public function testControllersContainNoSql(): void
    {
        foreach (self::phpFiles('app') as $file) {
            if (!str_contains($file, DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $code = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/\b(SELECT|INSERT|UPDATE|DELETE)\s+(\*|INTO|FROM|[a-z_]+\s+SET)/i', $code, basename($file) . ' must not contain SQL (plan.md §30 rule 17).');
            self::assertStringNotContainsString('->pdo()', $code, basename($file) . ' must not touch PDO.');
        }
    }

    public function testNoDateFunctionsWithServerTimezoneInModules(): void
    {
        foreach (self::phpFiles('app/Modules') as $file) {
            self::assertDoesNotMatchRegularExpression('/\b(strtotime|date)\s*\(/', (string) file_get_contents($file), basename($file) . ' must use DateTimeService/Clock.');
        }
    }

    public function testTemplatesUseNoInlineScriptsOrHandlers(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/resources/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }
            $html = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, $file->getFilename() . ': inline <script> breaks the CSP.');
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, $file->getFilename() . ': inline event handlers break the CSP.');
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, $file->getFilename() . ': inline style attributes break the CSP.');
            self::assertStringNotContainsString('|raw', str_replace('extra|raw', '', $html), $file->getFilename() . ': |raw needs review.');
        }
    }

    public function testMigrationsNeverCascadeFromTenants(): void
    {
        foreach (glob(self::root() . '/database/migrations/*.sql') ?: [] as $file) {
            self::assertDoesNotMatchRegularExpression(
                '/REFERENCES\s+tenants\s*\(id\)\s*ON DELETE\s+(CASCADE|SET NULL)/i',
                (string) file_get_contents($file),
                basename($file) . ' violates D-024.',
            );
        }
    }
}
