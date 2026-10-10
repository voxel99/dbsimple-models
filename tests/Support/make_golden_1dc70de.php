<?php

/**
 * Снимает golden-снимок ToArrayGolden::snapshot() на исходниках 1dc70de.
 *
 *   git worktree add /tmp/jam-1dc70de 1dc70de
 *   php tests/Support/make_golden_1dc70de.php /tmp/jam-1dc70de/src
 *
 * Тесты и фикстуры берутся из текущего дерева, классы Jam\Models\ — из переданного каталога.
 */

declare(strict_types=1);

$src = rtrim($argv[1] ?? '', '/');
if (!is_dir($src)) {
    fwrite(STDERR, "usage: php make_golden_1dc70de.php <1dc70de/src>\n");
    exit(1);
}
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

// Регистрируется после composer (тот добавляет свой загрузчик в начало очереди)
spl_autoload_register(static function (string $class) use ($src, $root): void {
    foreach (['Jam\\Models\\Tests\\' => $root . '/tests/', 'Jam\\Models\\' => $src . '/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
}, true, true);

if (!str_starts_with((string) (new ReflectionClass(Jam\Models\Model::class))->getFileName(), realpath($src))) {
    fwrite(STDERR, "Jam\\Models\\Model is not loaded from $src\n");
    exit(1);
}

$snap = Jam\Models\Tests\Support\ToArrayGolden::snapshot();
file_put_contents(
    Jam\Models\Tests\Support\ToArrayGolden::FILE,
    json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);
echo 'written ' . Jam\Models\Tests\Support\ToArrayGolden::FILE . "\n";
