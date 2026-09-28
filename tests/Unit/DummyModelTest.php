<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\ModelException;
use Jam\Models\Tests\Fixtures\Models\Role;
use Jam\Models\Tests\Support\ModelTestCase;

final class DummyModelTest extends ModelTestCase
{
    public function testAll(): void
    {
        $this->assertSame(['admin', 'editor', 'viewer'], Role::instance()->all()->column('code'));
    }

    public function testWhereEquals(): void
    {
        $this->assertSame('Editor', Role::instance()->where('code = ?', 'editor')->first()->title);
        $this->assertSame(2, Role::instance()->id(2)->first()->id);
    }

    public function testWhereInAndOr(): void
    {
        $this->assertSame(['admin', 'viewer'], Role::instance()->where('id IN (?a)', [1, 3])->column('code'));
        $this->assertSame(['admin', 'viewer'], Role::instance()->where('id = 1 OR id = 3')->column('code'));
        $this->assertSame(['editor'], Role::instance()->where("id = 2 AND code = 'editor'")->column('code'));
    }

    public function testOrderLimitOffset(): void
    {
        $this->assertSame([3, 2], Role::instance()->orderBy('id DESC')->limit(2)->column('id'));
        $this->assertSame([2, 3], Role::instance()->limit(2)->offset(1)->column('id'));
    }

    public function testUnsupportedConditionThrows(): void
    {
        $this->expectException(ModelException::class);
        Role::instance()->where('id > 1')->all();
    }

    public function testIsReadonly(): void
    {
        $role = Role::instance()->first();
        $role->title = 'Changed';
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('DummyModel is readonly');
        $role->save();
    }
}
