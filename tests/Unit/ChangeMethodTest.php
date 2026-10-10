<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\Tests\Support\ModelTestCase;

/**
 * Методы change{Prop}($new, $old), вызываемые при присваивании свойства модели.
 */
final class ChangeMethodTest extends ModelTestCase
{
    public function testChangeMethodReceivesNewAndOldValueOnDbLayer(): void
    {
        $model = new ChangeFooModel();
        $model->foo = 'a';
        $model->foo = 'b';

        $this->assertSame([['a', null], ['b', 'A']], $model->calls);
        $this->assertSame('B', $model->foo);
        $this->assertSame('B', $model->getDataDB()['foo']);
    }

    public function testChangeMethodReceivesNewAndOldValueOnExternalLayer(): void
    {
        $model = new ChangeFooModel();
        $model->{'foo:ex'} = 'a';
        $model->{'foo:ex'} = 'b';

        $this->assertSame([['a', null], ['b', 'A']], $model->calls);
        $this->assertSame('B', $model->getDataEX()['foo']);
        $this->assertArrayNotHasKey('foo', $model->getDataDB());
    }

    public function testOldValueIsReadFromTheAssignedLayer(): void
    {
        $model = new ChangeFooModel();
        $model->foo = 'db';
        $model->{'foo:ex'} = 'ex';

        // Старое значение ищется в слое external, а там пусто
        $this->assertSame([['db', null], ['ex', null]], $model->calls);
    }

    public function testChangeMethodReturningNullForNonNullValueThrows(): void
    {
        $model = new ChangeFooModel();
        $model->returnNull = true;

        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('::changeFoo must return a value');
        $model->foo = 'x';
    }

    public function testChangeMethodMayReturnNullForNullValue(): void
    {
        $model = new ChangeFooModel();
        $model->returnNull = true;
        $model->foo = null;

        $this->assertSame([[null, null]], $model->calls);
        $this->assertNull($model->foo);
    }

    public function testModelWithoutChangeMethodsAssignsAsBefore(): void
    {
        $model = new PlainChangeModel();
        $model->foo = 'a';
        $model->bar = 'b';
        $model->foo = 'c';

        $this->assertSame('c', $model->foo);
        $this->assertSame('b', $model->bar);
        $this->assertNull($model->findChangePropertyMethod('foo', 'x', 'db'));
    }

    public function testInheritedChangeMethodAndSeparateCachesPerClass(): void
    {
        // Сначала прогреваем кеш родителя и класса без change*, потом наследника — и наоборот
        $parent = new ChangeFooModel();
        $parent->bar = 'p';
        $plain = new PlainChangeModel();
        $plain->foo = 'plain';
        $child = new ChangeFooChildModel();
        $child->foo = 'x';
        $child->bar = 'y';
        $plain->bar = 'plain';
        $parent->foo = 'z';

        $this->assertSame([['x', null]], $child->calls);
        $this->assertSame('X', $child->foo);
        $this->assertSame('bar:y', $child->bar);
        $this->assertSame('p', $parent->bar);
        $this->assertSame('Z', $parent->foo);
        $this->assertSame('plain', $plain->foo);
        $this->assertSame('plain', $plain->bar);
    }

    public function testUnderscoredPropertyMapsToCamelCaseMethod(): void
    {
        $model = new ChangeFooModel();
        $model->log_label_for_update = 'label';

        $this->assertSame('[label]', $model->log_label_for_update);
    }

    public function testChangeMethodCanWriteGuardedFields(): void
    {
        $model = new ChangeFooModel();
        $model->foo = 'a';
        $this->assertSame('a', $model->secret);

        // После выхода из changeFoo охранник снова бодрствует
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('secret is guarded');
        $model->secret = 'hacked';
    }

    public function testFindChangePropertyMethodReturnsClosure(): void
    {
        $model = new ChangeFooModel();
        $model->foo = 'a';

        $closure = $model->findChangePropertyMethod('foo', 'b', 'db');
        $this->assertInstanceOf(\Closure::class, $closure);
        $this->assertSame('B', $closure());
        $this->assertSame(['b', 'A'], $model->calls[1]);
        $this->assertNull($model->findChangePropertyMethod('bar', 'b', 'db'));
    }
}

class ChangeFooModel extends Model
{
    protected string $fields = 'id, foo, bar, secret, log_label_for_update';
    protected string $guarded = 'secret';

    public array $calls = [];
    public bool $returnNull = false;

    public function changeFoo($value, $oldValue)
    {
        $this->calls[] = [$value, $oldValue];
        if ($this->returnNull) {
            return null;
        }
        if ($value !== null) {
            $this->secret = $value;
        }
        return $value === null ? null : strtoupper($value);
    }

    public function changeLogLabelForUpdate($value, $oldValue)
    {
        return '[' . $value . ']';
    }
}

class ChangeFooChildModel extends ChangeFooModel
{
    public function changeBar($value, $oldValue)
    {
        return 'bar:' . $value;
    }
}

class PlainChangeModel extends Model
{
    protected string $fields = 'id, foo, bar';
}
