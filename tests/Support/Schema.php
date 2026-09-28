<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Support;

/**
 * DDL тестовой схемы для SQLite и MySQL/MariaDB.
 */
final class Schema
{
    /** @var array<string, array{sqlite: string, mysql: string}> */
    private const TABLES = [
        'users' => [
            'sqlite' => 'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(255) NOT NULL DEFAULT "",
                name VARCHAR(255) NULL,
                password_hash VARCHAR(255) NULL,
                is_active TINYINT NOT NULL DEFAULT 0,
                roles VARCHAR(255) NULL,
                settings TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )',
            'mysql' => 'CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL DEFAULT "",
                name VARCHAR(255) NULL,
                password_hash VARCHAR(255) NULL,
                is_active TINYINT NOT NULL DEFAULT 0,
                roles VARCHAR(255) NULL,
                settings TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )',
        ],
        'profiles' => [
            'sqlite' => 'CREATE TABLE profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL DEFAULT 0,
                bio TEXT NULL,
                website VARCHAR(255) NULL
            )',
            'mysql' => 'CREATE TABLE profiles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL DEFAULT 0,
                bio TEXT NULL,
                website VARCHAR(255) NULL
            )',
        ],
        'categories' => [
            'sqlite' => 'CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NOT NULL DEFAULT 0,
                title VARCHAR(255) NOT NULL DEFAULT "",
                slug VARCHAR(255) NULL
            )',
            'mysql' => 'CREATE TABLE categories (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                parent_id INT UNSIGNED NOT NULL DEFAULT 0,
                title VARCHAR(255) NOT NULL DEFAULT "",
                slug VARCHAR(255) NULL
            )',
        ],
        'posts' => [
            'sqlite' => 'CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL DEFAULT 0,
                category_id INTEGER NOT NULL DEFAULT 0,
                title VARCHAR(255) NOT NULL DEFAULT "",
                body TEXT NULL,
                status TINYINT NOT NULL DEFAULT 0,
                flags INTEGER NOT NULL DEFAULT 0,
                views INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                deleted_at DATETIME NULL
            )',
            'mysql' => 'CREATE TABLE posts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL DEFAULT 0,
                category_id INT UNSIGNED NOT NULL DEFAULT 0,
                title VARCHAR(255) NOT NULL DEFAULT "",
                body TEXT NULL,
                status TINYINT NOT NULL DEFAULT 0,
                flags INT UNSIGNED NOT NULL DEFAULT 0,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                deleted_at DATETIME NULL
            )',
        ],
        'comments' => [
            'sqlite' => 'CREATE TABLE comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0,
                body TEXT NULL,
                is_approved TINYINT NOT NULL DEFAULT 0
            )',
            'mysql' => 'CREATE TABLE comments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                post_id INT UNSIGNED NOT NULL DEFAULT 0,
                user_id INT UNSIGNED NOT NULL DEFAULT 0,
                body TEXT NULL,
                is_approved TINYINT NOT NULL DEFAULT 0
            )',
        ],
        'tags' => [
            'sqlite' => 'CREATE TABLE tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(64) NOT NULL DEFAULT "" UNIQUE
            )',
            'mysql' => 'CREATE TABLE tags (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NOT NULL DEFAULT "",
                UNIQUE KEY uniq_name (name)
            )',
        ],
        'post_tag' => [
            'sqlite' => 'CREATE TABLE post_tag (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                UNIQUE (post_id, tag_id)
            )',
            'mysql' => 'CREATE TABLE post_tag (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                post_id INT UNSIGNED NOT NULL,
                tag_id INT UNSIGNED NOT NULL,
                UNIQUE KEY uniq_post_tag (post_id, tag_id)
            )',
        ],
        'activities' => [
            'sqlite' => 'CREATE TABLE activities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL DEFAULT 0,
                subject_type VARCHAR(32) NOT NULL DEFAULT "",
                subject_id INTEGER NOT NULL DEFAULT 0
            )',
            'mysql' => 'CREATE TABLE activities (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL DEFAULT 0,
                subject_type VARCHAR(32) NOT NULL DEFAULT "",
                subject_id INT UNSIGNED NOT NULL DEFAULT 0
            )',
        ],
        'options' => [
            'sqlite' => 'CREATE TABLE options (
                name VARCHAR(64) NOT NULL PRIMARY KEY,
                value TEXT NULL
            )',
            'mysql' => 'CREATE TABLE options (
                name VARCHAR(64) NOT NULL PRIMARY KEY,
                value TEXT NULL
            )',
        ],
    ];

    public static function create(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'sqlite' : 'mysql';
        foreach (array_keys(self::TABLES) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        foreach (self::TABLES as $ddl) {
            $pdo->exec($ddl[$driver]);
        }
    }
}
