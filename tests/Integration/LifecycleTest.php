<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\Tag;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\DatabaseTestCase;

/**
 * События, Timestamps, SoftDeletes.
 */
final class LifecycleTest extends DatabaseTestCase
{
    public function testEventsOrderAndPayload(): void
    {
        $log = [];
        $tag = new Tag(['name' => 'php']);
        $tag->on(Model::EVENT_CREATING, function (array $row) use (&$log) {
            $log[] = 'creating ' . $row['name'];
            $row['name'] = strtoupper($row['name']); // можно менять данные перед INSERT
            return $row;
        });
        $tag->on(Model::EVENT_CREATED, function ($id, array $row) use (&$log) {
            $log[] = "created #$id";
            return $row;
        });
        $tag->on(Model::EVENT_UPDATING, function ($id, array $row) use (&$log) {
            $log[] = "updating #$id " . implode(',', array_keys($row)); // все загруженные поля, включая pk
            return $row;
        });
        $tag->on(Model::EVENT_UPDATED, function ($id, array $row) use (&$log) {
            $log[] = "updated #$id";
            return $row;
        });
        $tag->on(Model::EVENT_DELETING, function (Model $model) use (&$log) {
            $log[] = 'deleting';
            return $model; // false — отменить удаление
        });
        $tag->on(Model::EVENT_DELETED, function (Model $model) use (&$log) {
            $log[] = 'deleted';
            return $model;
        });

        $tag->save();
        $tag->name = 'orm';
        $tag->save();
        $tag->delete();

        $this->assertSame(
            ['creating php', 'created #1', 'updating #1 name,id', 'updated #1', 'deleting', 'deleted'],
            $log
        );
        $this->assertQueryLogContains("INSERT INTO tags (`name`) VALUES ('PHP')");
    }

    public function testCreatingHandlerCanCancelInsert(): void
    {
        $tag = new Tag(['name' => 'nope']);
        $tag->on(Model::EVENT_CREATING, fn(array $row) => false);

        $this->assertSame(0, $tag->save());
        $this->assertSame(0, Tag::instance()->count());
    }

    public function testUpdatingHandlerCanSkipUpdate(): void
    {
        (new Tag(['name' => 'php']))->save();
        $tag = Tag::instance()->first();
        $tag->on(Model::EVENT_UPDATING, fn($id, array $row) => Model::makeNotUpdateble($row));
        $tag->name = 'changed';
        $tag->save();

        $this->assertSame('php', Tag::instance()->value('name'));
    }

    public function testDeletingHandlerCanCancelDelete(): void
    {
        (new Tag(['name' => 'php']))->save();
        $tag = Tag::instance()->first();
        $tag->on(Model::EVENT_DELETING, fn() => false);
        $tag->delete();

        $this->assertSame(1, Tag::instance()->count());
    }

    public function testRetrievedHandlerOnBuilder(): void
    {
        (new Tag(['name' => 'php']))->save();
        $builder = Tag::instance();
        $builder->on(Model::EVENT_RETRIEVED, function (array $rows) {
            foreach ($rows as &$row) {
                $row['name'] = '#' . $row['name'];
            }
            return $rows;
        });

        $this->assertSame(['#php'], $builder->all()->column('name'));
    }

    public function testTimestamps(): void
    {
        $user = new User(['email' => 'a@x.io']);
        $user->save();
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $user->created_at);
        $this->assertNull($user->updated_at, 'updated_at is set on update only');

        $user->name = 'A';
        $user->save();
        $this->assertNotNull($user->updated_at);
        $this->assertNotNull(User::instance()->id(1)->value('updated_at'));
    }

    public function testExplicitCreatedAtIsKept(): void
    {
        (new User(['email' => 'a@x.io', 'created_at' => '2020-01-01 00:00:00']))->save();
        $this->assertSame('2020-01-01 00:00:00', User::instance()->value('created_at'));
    }

    public function testSoftDeleteAndRestore(): void
    {
        (new Post(['title' => 'A']))->save();
        (new Post(['title' => 'B']))->save();

        $post = Post::instance()->id(1)->first();
        $post->delete();

        $this->assertTrue($post->trashed());
        $this->assertSame(['B'], Post::instance()->column('title'));
        $this->assertSame(['A', 'B'], Post::instance()->withTrashed()->orderBy('id')->column('title'));
        // withTrashed() действует на один запрос
        $builder = Post::instance()->withTrashed();
        $builder->column('id');
        $this->assertSame(1, $builder->count());

        $trashed = Post::instance()->withTrashed()->id(1)->first();
        $trashed->restore();
        $this->assertFalse($trashed->trashed());
        $this->assertSame(2, Post::instance()->count());
    }

    public function testOnlyTrashedViaExplicitCondition(): void
    {
        (new Post(['title' => 'A']))->save();
        (new Post(['title' => 'B']))->save();
        Post::instance()->id(2)->first()->delete();

        // Явное условие по deleted_at отключает автоматический фильтр
        $this->assertSame(['B'], Post::instance()->where('deleted_at IS NOT NULL')->column('title'));
    }

    public function testForceDelete(): void
    {
        (new Post(['title' => 'A']))->save();
        Post::instance()->id(1)->first()->forceDelete();

        $this->assertSame(0, Post::instance()->withTrashed()->count());
    }

    public function testFreshTimestampCanBeOverridden(): void
    {
        $model = new class (['name' => 'fixed']) extends Tag {
            public static function freshTimestamp(): string
            {
                return '2000-01-01 00:00:00';
            }
        };
        $this->assertSame('2000-01-01 00:00:00', $model::freshTimestamp());

        (new Post(['title' => 'A']))->save();
        $post = new class extends Post {
            public static function freshTimestamp(): string
            {
                return '1999-12-31 23:59:59';
            }
        };
        $post = $post->id(1)->first();
        $post->delete();
        $this->assertSame('1999-12-31 23:59:59', Post::instance()->withTrashed()->value('deleted_at'));
    }
}
