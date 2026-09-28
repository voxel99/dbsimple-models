<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\Model;
use Jam\Models\Tests\Fixtures\Models\TypedRecord;
use Jam\Models\Tests\Support\ModelTestCase;

/**
 * Модель хранит значение в двух слоях: db (как в БД) и ex (внешнее, для PHP-кода).
 * $model->field — внешнее значение, $model->{'field:db'} — значение для БД.
 */
final class CastingTest extends ModelTestCase
{
    public function testScalarCastsFromDatabaseStrings(): void
    {
        $r = new TypedRecord([
            'id' => '5',
            'qty' => '12',
            'big' => '18446744073709551615',
            'ratio' => '1.5',
            'day' => '2024-01-02 10:11:12',
            'moment' => '2024-01-02 10:11:12',
            'code' => 42,
        ]);

        $this->assertSame(5, $r->id);
        $this->assertSame(12, $r->qty);
        $this->assertSame('18446744073709551615', $r->big);
        $this->assertSame(1.5, $r->ratio);
        $this->assertSame('2024-01-02', $r->day);
        $this->assertSame('2024-01-02 10:11:12', $r->moment);
        $this->assertSame('42', $r->code);
        // Скалярные касты не меняют db-слой
        $this->assertSame('12', $r->{'qty:db'});
    }

    public function testBool(): void
    {
        $r = new TypedRecord(['active' => '1']);
        $this->assertTrue($r->active);
        $this->assertSame('1', $r->{'active:db'});

        $r->active = false;
        $this->assertFalse($r->active);
        $this->assertSame(0, $r->{'active:db'});
    }

    public function testNullTypeTurnsEmptyValuesIntoNull(): void
    {
        $r = new TypedRecord(['parent_id' => '0']);
        $this->assertNull($r->parent_id);
        $this->assertArrayNotHasKey('parent_id', $r->toArray());
    }

    public function testDelimiterWithCustomSeparator(): void
    {
        $r = new TypedRecord(['tags' => 'a|b|c']);
        $this->assertSame(['a', 'b', 'c'], $r->tags);

        $r->tags = ['x', 'y'];
        $this->assertSame('x|y', $r->{'tags:db'});
    }

    public function testJsonColumnAndTopLevelKeys(): void
    {
        $r = new TypedRecord(['options' => '{"theme":"dark","notify":{"email":1,"sms":0}}']);
        $this->assertEquals((object) ['theme' => 'dark', 'notify' => (object) ['email' => 1, 'sms' => 0]], $r->options);

        // top-level ключ пишется внутрь JSON-колонки
        $r->lang = 'ru';
        $r->x_beta = true;
        $this->assertSame('ru', $r->options->lang);
        $this->assertTrue($r->options->x_beta);

        // Подтип flags: наружу (toArray) — yes/no, в БД — 1/0
        $r->notify = ['email' => 'yes', 'sms' => 'no'];
        $this->assertSame(['email' => 'yes', 'sms' => 'no'], (array) $r->toArray()['options']->notify);
        $this->assertSame(
            ['theme' => 'dark', 'notify' => ['email' => 1, 'sms' => 0], 'lang' => 'ru', 'x_beta' => true],
            json_decode($r->{'options:db'}, true)
        );
    }

    public function testFlagsBitmask(): void
    {
        // read=bit0, write=bit1, level=bits2-3
        $r = new TypedRecord(['bits' => 0b1101]);
        $this->assertEquals((object) ['read' => 1, 'write' => 0, 'level' => 3], $r->bits);
        $this->assertEquals((object) ['read' => 'yes', 'write' => 'no', 'level' => 3], $r->toArray()['bits']);

        $r->bits = ['read' => 'no', 'write' => 'yes', 'level' => 2];
        $this->assertSame(0b1010, $r->{'bits:db'});
    }

    public function testIpIsStoredInBinaryForm(): void
    {
        $r = new TypedRecord(['ip' => inet_pton('10.0.0.1')]);
        $this->assertSame('10.0.0.1', $r->ip);

        $r->ip = '2001:db8::1';
        $this->assertSame(inet_pton('2001:db8::1'), $r->{'ip:db'});
    }

    public function testCustomClosureCast(): void
    {
        $r = new TypedRecord(['price' => 1999]);
        $this->assertSame(19.99, $r->price);

        $r->price = 12.34;
        $this->assertSame(1234, $r->{'price:db'});
        $this->assertSame(12.34, $r->toArray()['price']);
    }

    public function testInternalDatabaseFlagExportsDbLayer(): void
    {
        $r = new TypedRecord(['active' => true, 'tags' => ['a', 'b'], 'options' => ['theme' => 'x']]);
        $this->assertEquals(
            ['tags' => 'a|b', 'options' => '{"theme":"x"}', 'active' => 1],
            $r->toArray(':_')
        );
    }

    public function testExplicitDbLayerAssignment(): void
    {
        $r = new TypedRecord();
        $r->{'tags:db'} = 'p|q';
        $this->assertSame(['p', 'q'], $r->tags);
    }

    public function testUnknownJsonSubtypeIsRejected(): void
    {
        $model = new class extends Model {
            protected string $fields = 'id, data';
            protected $types = ['json' => 'data(a: nope)'];
        };
        $this->expectException(\Jam\Models\ModelException::class);
        $model->getCast();
    }
}
