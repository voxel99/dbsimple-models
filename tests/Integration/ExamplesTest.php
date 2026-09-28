<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Каждый пример из examples/ должен выполняться без ошибок.
 */
final class ExamplesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function examples(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/examples/[0-9]*.php') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('examples')]
    public function testExampleRuns(string $file): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1';
        exec($cmd, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", array_slice($output, -30)));
        $this->assertStringNotContainsString('Warning:', implode("\n", $output));
        $this->assertStringNotContainsString('Deprecated:', implode("\n", $output));
    }
}
