<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\Tests\Fixtures\Models\Tag;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\DatabaseTestCase;

final class CrudTest extends DatabaseTestCase
{
    public function testInsertAssignsPrimaryKey(): void
    {
        $user = new User(['email' => 'alice@example.com', 'name' => 'Alice', 'is_active' => true, 'roles' => ['admin', 'editor']]);
        $id = $user->save();

        $this->assertSame(1, $id);
        $this->assertSame(1, $user->id);
        $this->assertQueryLogContains("INSERT INTO users (`email`, `name`, `is_active`, `roles`, `created_at`) VALUES ('alice@example.com', 'Alice', 1, 'admin,editor'");
    }

    public function testFindAndCastsFromDatabase(): void
    {
        (new User(['email' => 'a@x.io', 'is_active' => true, 'roles' => ['a', 'b'], 'settings' => ['theme' => 'dark']]))->save();

        $user = User::instance()->id(1)->first();
        $this->assertSame(1, $user->id);
        $this->assertTrue($user->is_active);
        $this->assertSame(['a', 'b'], $user->roles);
        $this->assertSame('dark', $user->settings->theme);
    }

    public function testUpdateWritesAllLoadedFieldsOrOnlyListed(): void
    {
        (new User(['email' => 'a@x.io', 'name' => 'A']))->save();
        $user = User::instance()->id(1)->first();
        $this->resetQueryLog();

        $user->name = 'B';
        $user->save();
        // save() на существующей записи обновляет все загруженные поля
        $this->assertQueryLogContains("UPDATE users SET `email` = 'a@x.io', `name` = 'B'");

        $this->resetQueryLog();
        $user->name = 'C';
        $user->update('name');
        $this->assertStringStartsWith("UPDATE users SET `name` = 'C', `updated_at` = ", $this->queries()[0]);
        $this->assertSame('C', User::instance()->id(1)->value('name'));
    }

    public function testUpdateByArray(): void
    {
        (new Tag(['name' => 'php']))->save();
        $tag = Tag::instance()->first();
        $tag->updateByArray(['name' => 'php8']);
        $this->assertSame('php8', Tag::instance()->id(1)->value('name'));
    }

    public function testNullIsIgnoredOnSaveNullValueWritesSqlNull(): void
    {
        (new User(['email' => 'a@x.io', 'name' => 'A']))->save();
        $user = User::instance()->id(1)->first();

        $user->name = null;          // null — «не трогать поле»
        $user->update('name');
        $this->assertSame('A', User::instance()->id(1)->value('name'));

        $user->name = Model::NULL_VALUE; // явный NULL в БД
        $user->update('name');
        $this->assertNull(User::instance()->id(1)->value('name'));
    }

    public function testPartialSelectDoesNotOverwriteOtherColumns(): void
    {
        (new User(['email' => 'a@x.io', 'name' => 'A']))->save();
        $user = User::instance()->id(1)->first('id, name');
        $user->name = 'B';
        $this->resetQueryLog();
        $user->save();

        $this->assertStringNotContainsString('email', $this->queries()[0]);
        $this->assertSame('a@x.io', User::instance()->id(1)->value('email'));
    }

    public function testDelete(): void
    {
        (new Tag(['name' => 'a']))->save();
        (new Tag(['name' => 'b']))->save();

        Tag::instance()->id(1)->first()->delete();
        $this->assertSame(['b'], Tag::instance()->column('name'));
    }

    public function testDeleteWhereAndDeleteListByPk(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $name) {
            (new Tag(['name' => $name]))->save();
        }
        Tag::instance()->deleteWhere('name IN (?a)', ['a', 'b']);
        Tag::instance()->deleteListByPk([3]);
        $this->assertSame(['d'], Tag::instance()->column('name'));
    }

    public function testIncByUpdateIsAtomic(): void
    {
        (new \Jam\Models\Tests\Fixtures\Models\Post(['title' => 'p', 'views' => 10]))->save();
        $post = \Jam\Models\Tests\Fixtures\Models\Post::instance()->first();
        $post->incByUpdate('views', 5);

        $this->assertSame(15, $post->views);
        $this->assertQueryLogContains('SET `views` = `views`+5');
    }

    public function testUpdateRaw(): void
    {
        (new User(['email' => 'a@x.io']))->save();
        (new User(['email' => 'b@x.io']))->save();

        User::updateRaw(['name' => 'Same'], [1, 2]);
        $this->assertSame(['Same', 'Same'], User::instance()->column('name'));
        // У модели с Timestamps updateRaw сам проставляет updated_at
        $this->assertQueryLogContains('`updated_at` = ');
    }

    public function testBulkInsertViaModelList(): void
    {
        $list = new \Jam\Models\ModelList(Tag::class, [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
        $this->resetQueryLog();
        $list->insert();

        $this->assertQueryCount(1);
        $this->assertSame("INSERT INTO tags (`name`) VALUES ('a'), ('b'), ('c')", $this->queries()[0]);
    }

    public function testFirstOnMissingRowReturnsEmptyModel(): void
    {
        $user = User::instance()->id(404)->first();
        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->isEmpty());
        $this->assertFalse($user->isExists());
    }

    public function testTransactionViaConnection(): void
    {
        $this->db->transaction();
        (new Tag(['name' => 'rolled-back']))->save();
        $this->db->rollback();

        $this->assertSame(0, Tag::instance()->count());
    }

    public function testSaveGuardedFieldThroughModelMethod(): void
    {
        $user = new User(['email' => 'a@x.io']);
        $user->setPassword('secret');
        $user->save();

        $fromDb = User::instance()->id(1)->first();
        $this->assertTrue($fromDb->checkPassword('secret'));
        $this->assertArrayNotHasKey('password_hash', $fromDb->toArray());
    }
}
