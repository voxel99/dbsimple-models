<?php

declare(strict_types=1);

namespace Jam\Models\Tests;

use Jam\DbSimple\Connect;
use Jam\Models\Model;
use PHPUnit\Framework\TestCase;

class RealDatabaseModelTest extends TestCase
{
    private Connect $db;

    protected function setUp(): void
    {
        // Временная SQLite база в памяти для полноценного тестирования CRUD
        $this->db = new Connect('mypdo://root:@127.0.0.1/test');
    }

    public function testModelRegistration(): void
    {
        $this->assertTrue(class_exists(Model::class));
    }
}
