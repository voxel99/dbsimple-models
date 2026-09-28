<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Support;

use Jam\Models\Model;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Базовый класс unit-тестов: соединение регистрируется (модели требуют его при создании),
 * но любой SQL-запрос приводит к падению теста.
 */
abstract class ModelTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new PdoSqlite(':memory:');
        $db->setLogger(static function ($db, $sql): void {
            if (is_string($sql) && !str_starts_with(ltrim($sql), '--')) {
                throw new RuntimeException('Unit test must not execute SQL: ' . $sql);
            }
        });
        Model::initDbSimple([Model::DB_MASTER => $db]);
    }

    protected function tearDown(): void
    {
        Model::initDbSimple([]);
        parent::tearDown();
    }
}
