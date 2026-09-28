<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\Model;
use Jam\Models\ModelList;
use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\ModelTestCase;
use stdClass;

/**
 * Флаги toArray(): строка вида ':cdh' или массив [Model::FLAG_* => bool|string].
 *   d — связи (по умолчанию включён, только если флаги не переданы вовсе)
 *   c — вычислимые поля, h — скрытые, x — extra, e — пустые (null) значения
 *   _ — db-представление (без кастов), s — Stringable, a — Scalarable
 */
final class ToArrayTest extends ModelTestCase
{
    private function user(): User
    {
        return new User([
            'id' => 1,
            'email' => 'a@b.c',
            'name' => 'Alice',
            'password_hash' => 'hash',
            'plain_password' => 'secret',
            'profile' => ['bio' => 'hi'],
            'posts' => [['id' => 10, 'title' => 'First', 'body' => 'Body of the first post']],
        ]);
    }

    public function testDefaultIncludesRelationsOnly(): void
    {
        $arr = $this->user()->toArray();
        $this->assertSame(['id', 'email', 'name', 'profile', 'posts'], array_keys($arr));
        $this->assertSame(['bio' => 'hi'], $arr['profile']);
    }

    public function testPassingFlagsDisablesDependenciesUnlessRequested(): void
    {
        $this->assertArrayNotHasKey('posts', $this->user()->toArray(':c'));
        $this->assertArrayHasKey('posts', $this->user()->toArray(':cd'));
    }

    public function testBooleanFlagsExceptComputedAreInheritedByRelations(): void
    {
        $user = $this->user();

        $arr = $user->toArray(':cdh');
        $this->assertSame('Alice <a@b.c>', $arr['display_name']);
        // c не наследуется связями — computed для связи включают через with('posts:c')
        $this->assertArrayNotHasKey('excerpt', $arr['posts'][0]);

        $arr = $user->toArray(null, null, ['posts' => ['flags' => [Model::FLAG_COMPUTABLE => true]]]);
        $this->assertSame('Body of the first po', $arr['posts'][0]['excerpt']);
    }

    public function testArrayFlags(): void
    {
        $arr = $this->user()->toArray([Model::FLAG_HIDDEN => true, Model::FLAG_EXTRA => true]);
        $this->assertSame('hash', $arr['password_hash']);
        $this->assertSame('secret', $arr['plain_password']);
        $this->assertArrayNotHasKey('posts', $arr);
    }

    public function testFlagWithArgumentLimitsFieldsSet(): void
    {
        // В массиве флагов можно перечислить конкретные поля: только display_name из computed
        $arr = $this->user()->toArray([Model::FLAG_COMPUTABLE => 'display_name']);
        $this->assertSame('Alice <a@b.c>', $arr['display_name']);

        // Флаги h и s принимают аргументы и в строковой форме: h(password_hash)
        $this->assertSame('hash', $this->user()->toArray(':h(password_hash)')['password_hash']);
    }

    public function testEmptyFlagKeepsNulls(): void
    {
        $user = new User(['id' => 1]);
        $user->name = Model::NULL_VALUE;
        $this->assertArrayNotHasKey('name', $user->toArray());
        $this->assertArrayHasKey('name', $user->toArray(':e'));
        $this->assertNull($user->toArray(':e')['name']);
    }

    public function testFieldsAndExcludeArguments(): void
    {
        $user = $this->user();
        // Список полей ограничивает обычные поля; связи остаются, пока включён флаг d (по умолчанию)
        $this->assertSame(['id', 'email', 'profile', 'posts'], array_keys($user->toArray(null, 'id,email')));
        $this->assertSame(['id' => 1, 'email' => 'a@b.c'], $user->toArray([], 'id,email'));
        $this->assertSame(['id', 'name', 'profile', 'posts'], array_keys($user->toArray(null, null, null, ['email'])));
    }

    public function testInvalidFlagThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Flag not found [z]');
        $this->user()->toArray(':z');
    }

    public function testToObjectConvertsRecursively(): void
    {
        $obj = $this->user()->toObject();
        $this->assertInstanceOf(stdClass::class, $obj);
        $this->assertInstanceOf(stdClass::class, $obj->profile);
        $this->assertIsArray($obj->posts);
        $this->assertInstanceOf(stdClass::class, $obj->posts[0]);
        $this->assertSame('First', $obj->posts[0]->title);
    }

    public function testModelListHelpers(): void
    {
        $list = new ModelList(Post::class, [
            ['id' => 1, 'title' => 'A', 'user_id' => 7],
            ['id' => 2, 'title' => 'B', 'user_id' => 7],
        ]);

        $this->assertCount(2, $list);
        $this->assertSame('A', $list->first()->title);
        $this->assertSame([1, 2], $list->column('id'));
        $this->assertSame([1 => 'A', 2 => 'B'], $list->pluck('title', 'id'));
        $this->assertSame([1, 2], array_keys($list->keyBy('id')));
        $this->assertSame([['id' => 1, 'title' => 'A', 'user_id' => 7], ['id' => 2, 'title' => 'B', 'user_id' => 7]], $list->toArray());

        $titles = [];
        $list->each(function (Post $p) use (&$titles) {
            $titles[] = $p->title;
        });
        $this->assertSame(['A', 'B'], $titles);
    }
}
