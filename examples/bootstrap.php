<?php

/**
 * Общая подготовка для примеров: in-memory SQLite, схема, журнал SQL.
 *
 * Запуск любого примера:  php examples/05-relations.php
 *
 * В реальном проекте соединение создаётся так (ленивое подключение к MySQL):
 *
 *     use Jam\DbSimple\Connect;
 *     use Jam\Models\Model;
 *
 *     $master = new Connect('mypdo://user:pass@127.0.0.1/app?enc=utf8mb4');
 *     $master->setErrorHandler(function (string $message, array $info) {
 *         throw new RuntimeException($message);   // по умолчанию DbSimple делает echo + exit()
 *     });
 *     Model::initDbSimple([Model::DB_MASTER => $master]);
 *
 * Здесь вместо MySQL — SQLite в памяти (адаптер из tests/Support), чтобы примеры работали «из коробки».
 */

declare(strict_types=1);

use Jam\Models\Model;
use Jam\Models\Tests\Support\PdoSqlite;

require __DIR__ . '/../vendor/autoload.php';

const EXAMPLES_SCHEMA = <<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(255) NOT NULL DEFAULT '',
    name VARCHAR(255) NULL,
    password_hash VARCHAR(255) NULL,
    is_active TINYINT NOT NULL DEFAULT 1,
    role_id INTEGER NOT NULL DEFAULT 3,
    interests VARCHAR(255) NULL,
    settings TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);
CREATE TABLE profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL DEFAULT 0,
    bio TEXT NULL,
    website VARCHAR(255) NULL
);
CREATE TABLE categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NOT NULL DEFAULT 0,
    title VARCHAR(255) NOT NULL DEFAULT '',
    slug VARCHAR(255) NULL
);
CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL DEFAULT 0,
    category_id INTEGER NOT NULL DEFAULT 0,
    title VARCHAR(255) NOT NULL DEFAULT '',
    body TEXT NULL,
    status TINYINT NOT NULL DEFAULT 0,
    flags INTEGER NOT NULL DEFAULT 0,
    views INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL
);
CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL DEFAULT 0,
    user_id INTEGER NOT NULL DEFAULT 0,
    body TEXT NULL,
    is_approved TINYINT NOT NULL DEFAULT 0
);
CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(64) NOT NULL DEFAULT '' UNIQUE
);
CREATE TABLE post_tag (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    UNIQUE (post_id, tag_id)
);
CREATE TABLE activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL DEFAULT 0,
    action VARCHAR(32) NOT NULL DEFAULT '',
    subject_type VARCHAR(32) NOT NULL DEFAULT '',
    subject_id INTEGER NOT NULL DEFAULT 0
);
SQL;

/**
 * Создаёт in-memory БД со схемой примеров и регистрирует её как master.
 */
function examples_connect(bool $logSql = true): PdoSqlite
{
    $db = new PdoSqlite(':memory:');
    $db->pdo()->exec(EXAMPLES_SCHEMA);
    $db->setErrorHandler(static function (string $message): void {
        throw new RuntimeException($message);
    });
    if ($logSql) {
        examples_log_sql($db);
    }
    Model::initDbSimple([Model::DB_MASTER => $db]);
    return $db;
}

/** Печатать каждый SQL-запрос (логгер DbSimple получает и строки статистики "-- N ms", их пропускаем). */
function examples_log_sql(\Jam\DbSimple\Database $db, string $prefix = 'SQL'): void
{
    $db->setLogger(static function ($db, $sql) use ($prefix): void {
        if (is_string($sql) && !str_starts_with(ltrim($sql), '--') && ($GLOBALS['examples_sql_enabled'] ?? true)) {
            echo "  [$prefix] ", trim((string) preg_replace('/\s+/', ' ', $sql)), "\n";
        }
    });
}

/** Выполнить блок без печати SQL (подготовка данных). */
function quietly(callable $fn): mixed
{
    $GLOBALS['examples_sql_enabled'] = false;
    try {
        return $fn();
    } finally {
        $GLOBALS['examples_sql_enabled'] = true;
    }
}

function section(string $title): void
{
    echo "\n=== ", $title, " ===\n";
}

function show(string $label, mixed $value): void
{
    $out = match (true) {
        is_string($value) => '"' . $value . '"',
        is_scalar($value) || $value === null => var_export($value, true),
        // короткие списки скаляров — в одну строку
        is_array($value) && array_is_list($value) && count(array_filter($value, 'is_scalar')) === count($value)
            => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    };
    echo '  ', $label, ': ', str_replace("\n", "\n  ", $out), "\n";
}

/** Небольшой набор данных, общий для примеров. */
function examples_seed(): void
{
    quietly(function (): void {
        $users = [
            ['email' => 'alice@example.com', 'name' => 'Alice', 'role_id' => 1, 'interests' => ['php', 'sql']],
            ['email' => 'bob@example.com', 'name' => 'Bob', 'role_id' => 2],
            ['email' => 'carol@example.com', 'name' => 'Carol', 'is_active' => false],
        ];
        foreach ($users as $data) {
            (new \Jam\Models\Examples\Models\User($data))->save();
        }
        (new \Jam\Models\Examples\Models\Profile(['user_id' => 1, 'bio' => 'Backend developer']))->save();
        (new \Jam\Models\Examples\Models\Category(['title' => 'Programming', 'slug' => 'programming']))->save();
        (new \Jam\Models\Examples\Models\Category(['title' => 'PHP', 'slug' => 'php', 'parent_id' => 1]))->save();

        $posts = [
            [1, 2, 'Typed properties in PHP', 1, 120],
            [1, 2, 'Eager loading without N+1', 1, 340],
            [2, 1, 'Draft about indexes', 0, 0],
            [2, 1, 'Transactions explained', 1, 75],
        ];
        foreach ($posts as [$userId, $categoryId, $title, $status, $views]) {
            (new \Jam\Models\Examples\Models\Post([
                'user_id' => $userId,
                'category_id' => $categoryId,
                'title' => $title,
                'body' => "Body of \"$title\". Lorem ipsum dolor sit amet, consectetur adipiscing elit.",
                'status' => $status,
                'views' => $views,
            ]))->save();
        }
        foreach ([[1, 2, 'Great post!', true], [1, 3, 'Thanks', true], [2, 3, 'Spam spam', false], [4, 1, 'Nice', true]] as [$postId, $userId, $body, $ok]) {
            (new \Jam\Models\Examples\Models\Comment(['post_id' => $postId, 'user_id' => $userId, 'body' => $body, 'is_approved' => $ok]))->save();
        }
        foreach (['php', 'orm', 'sql'] as $name) {
            (new \Jam\Models\Examples\Models\Tag(['name' => $name]))->save();
        }
        foreach ([[1, 1], [2, 1], [2, 2], [4, 3]] as [$postId, $tagId]) {
            (new \Jam\Models\Examples\Models\PostTag(['post_id' => $postId, 'tag_id' => $tagId]))->save();
        }
    });
}
