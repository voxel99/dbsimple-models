<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Support;

use Jam\DbSimple\Adapter\Mypdo;
use Jam\DbSimple\Adapter\Sqlite;
use Jam\DbSimple\Database;
use PDO;
use RuntimeException;

/**
 * Фабрика тестовой БД.
 *
 * По умолчанию — SQLite in-memory (ничего не нужно устанавливать).
 * Если задана переменная окружения DBSIMPLE_TEST_MYSQL (DSN вида
 * mysql://user:pass@127.0.0.1/dbname), тесты идут на MySQL/MariaDB.
 */
final class TestDatabase
{
    /** @var array<int, string> SQL, выполненный с момента последнего resetLog() */
    public static array $log = [];

    public static function driver(): string
    {
        return getenv('DBSIMPLE_TEST_MYSQL') ? 'mysql' : 'sqlite';
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    /**
     * Создаёт соединение, накатывает схему, вешает обработчик ошибок (исключения) и логгер.
     */
    public static function connect(): Database
    {
        if (self::isMysql()) {
            $parsed = parse_url((string) getenv('DBSIMPLE_TEST_MYSQL'));
            $db = new Mypdo([
                'host' => $parsed['host'] ?? '127.0.0.1',
                'port' => $parsed['port'] ?? null,
                'user' => $parsed['user'] ?? 'root',
                'pass' => $parsed['pass'] ?? '',
                'path' => $parsed['path'] ?? '/test',
                'enc' => 'utf8mb4',
            ]);
            $pdo = new PDO(
                sprintf('mysql:host=%s;%sdbname=%s', $parsed['host'], isset($parsed['port']) ? 'port=' . $parsed['port'] . ';' : '', ltrim($parsed['path'], '/')),
                $parsed['user'] ?? 'root',
                $parsed['pass'] ?? ''
            );
        } else {
            $db = new Sqlite(['path' => ':memory:']);
            $pdo = $db->getPdo();
        }

        Schema::create($pdo);

        $db->setErrorHandler(static function (string $message, array $info): void {
            throw new RuntimeException('SQL error: ' . $message . "\n" . ($info['query'] ?? ''));
        });
        $db->setLogger(static function ($db, $sql): void {
            // Логгер вызывается и для запросов, и для строк статистики ("  -- 1 ms; returned ...").
            if (is_string($sql) && !str_starts_with(ltrim($sql), '--')) {
                self::$log[] = trim(preg_replace('/\s+/', ' ', $sql));
            }
        });
        self::$log = [];

        return $db;
    }
}
