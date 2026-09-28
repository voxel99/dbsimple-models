<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Support;

use Jam\DbSimple\Database;
use Jam\Models\Model;
use PHPUnit\Framework\TestCase;

/**
 * Базовый класс интеграционных тестов: свежая схема на каждый тест + журнал SQL.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = TestDatabase::connect();
        Model::initDbSimple([Model::DB_MASTER => $this->db]);
    }

    protected function tearDown(): void
    {
        Model::initDbSimple([]);
        parent::tearDown();
    }

    /** Сбросить журнал SQL (например, после подготовки данных). */
    protected function resetQueryLog(): void
    {
        TestDatabase::$log = [];
    }

    /** @return array<int, string> */
    protected function queries(): array
    {
        return TestDatabase::$log;
    }

    protected function assertQueryCount(int $expected, string $message = ''): void
    {
        $this->assertCount($expected, TestDatabase::$log, $message ?: "Executed SQL:\n" . implode("\n", TestDatabase::$log));
    }

    protected function assertQueryLogContains(string $needle): void
    {
        foreach (TestDatabase::$log as $sql) {
            if (str_contains($sql, $needle)) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail(sprintf("No query contains [%s]. Executed SQL:\n%s", $needle, implode("\n", TestDatabase::$log)));
    }

    protected function requireMysql(): void
    {
        if (!TestDatabase::isMysql()) {
            $this->markTestSkipped('MySQL-only feature: set DBSIMPLE_TEST_MYSQL to run');
        }
    }
}
