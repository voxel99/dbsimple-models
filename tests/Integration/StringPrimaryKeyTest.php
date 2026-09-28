<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Tests\Fixtures\Models\Option;
use Jam\Models\Tests\Support\DatabaseTestCase;

final class StringPrimaryKeyTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query("INSERT INTO options (name, value) VALUES ('site', '{\"title\":\"Blog\"}'), ('mail', 'null')");
        $this->resetQueryLog();
    }

    public function testFindByStringKey(): void
    {
        $option = Option::instance()->id('site')->first();
        $this->assertSame('Blog', $option->value->title);
        $this->assertSame("SELECT * FROM options WHERE `name` = 'site' LIMIT 1", $this->queries()[0]);
    }

    public function testUpdateAndDeleteByStringKey(): void
    {
        $option = Option::instance()->id('site')->first();
        $option->value = ['title' => 'News'];
        $option->update();
        $this->assertSame('News', Option::instance()->id('site')->first()->value->title);

        $option->delete();
        $this->assertSame(['mail'], Option::instance()->column('name'));
    }

    public function testSaveWithPresetStringKeyUsesUpsert(): void
    {
        // Для pk !== 'id' с заданным значением save() делает INSERT ... ON DUPLICATE KEY UPDATE
        $this->requireMysql();

        $site = new Option(['name' => 'site', 'value' => ['title' => 'Upserted']]);
        $site->save();
        (new Option(['name' => 'new', 'value' => ['x' => 1]]))->save();

        // ключ строки не должен перезаписываться (раньше LAST_INSERT_ID('site') превращал его в '0')
        $this->assertSame(['mail', 'new', 'site'], Option::instance()->orderBy('name')->column('name'));
        $this->assertSame('Upserted', Option::instance()->id('site')->first()->value->title);
        $this->assertSame('site', $site->name);
    }
}
