<?php

declare(strict_types=1);

namespace Jam\Models\Tests;

use Jam\Models\Model;
use PHPUnit\Framework\TestCase;

class BasicModelTest extends TestCase
{
    protected function setUp(): void
    {
        // Mock connection
        $mockDb = new class implements \Jam\DbSimple\DatabaseInterface {
            public function blob($blob_id = null) {}
            public function transaction($mode = null) {}
            public function commit() {}
            public function rollback() {}
            public function select(...$query) { return []; }
            public function selectPage(&$total, ...$query) { return []; }
            public function selectRow(...$query) { return []; }
            public function selectCol(...$query) { return []; }
            public function selectCell(...$query) { return null; }
            public function query(...$query) { return 0; }
            public function subquery(...$query) { return new \Jam\DbSimple\SubQuery($query); }
            public function escape($s, $isIdent = false) { return $isIdent ? '`' . $s . '`' : "'" . $s . "'"; }
            public function setLogger($logger) { return null; }
            public function setIdentPrefix($prx) { return $prx; }
            public function setClassName($name) { return $this; }
            public function getStatistics() { return []; }
        };

        Model::initDbSimple([
            Model::DB_MASTER => $mockDb,
        ]);
    }

    public function testModelInstantiationAndAttributes(): void
    {
        $model = new class(['id' => 10, 'title' => 'Test Item']) extends Model {
            protected $table = 'items';
            protected string $fields = 'id,title';
        };

        $this->assertSame(10, $model->id);
        $this->assertSame('Test Item', $model->title);
        $this->assertSame(['id' => 10, 'title' => 'Test Item'], $model->toArray());
    }

    public function testComputedFields(): void
    {
        $model = new class(['first_name' => 'John', 'last_name' => 'Doe']) extends Model {
            protected string $fields = 'first_name,last_name';
            protected string $computed = 'full_name';

            public function full_name(): string
            {
                return $this->first_name . ' ' . $this->last_name;
            }
        };

        $this->assertSame('John Doe', $model->full_name);
        $array = $model->toArray(':c');
        $this->assertArrayHasKey('full_name', $array);
        $this->assertSame('John Doe', $array['full_name']);
    }

    public function testCasting(): void
    {
        $model = new class(['is_active' => '1', 'tags' => 'php,dbsimple,orm']) extends Model {
            protected string $fields = 'is_active,tags';
            protected $types = [
                'bool' => 'is_active',
                'delim' => 'tags',
            ];
        };

        $this->assertTrue($model->is_active);
        $this->assertSame(['php', 'dbsimple', 'orm'], $model->tags);
    }
}
