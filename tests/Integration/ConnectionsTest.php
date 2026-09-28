<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\DatabaseTestCase;
use Jam\Models\Tests\Support\PdoSqlite;
use Jam\Models\Tests\Support\Schema;

/**
 * Несколько соединений: запись в master, чтение из реплики.
 */
final class ConnectionsTest extends DatabaseTestCase
{
    private PdoSqlite $replica;

    protected function setUp(): void
    {
        parent::setUp();
        // Отдельная БД в роли реплики: так видно, из какой базы пришли данные
        $this->replica = new PdoSqlite(':memory:');
        Schema::create($this->replica->pdo());
        $this->replica->pdo()->exec("INSERT INTO users (id, email, name) VALUES (1, 'replica@x.io', 'From replica')");
        $this->replica->pdo()->exec("INSERT INTO posts (id, user_id, title) VALUES (1, 1, 'Replica post')");

        Model::initDbSimple([Model::DB_MASTER => $this->db, Model::DB_SLAVE => $this->replica]);
        (new User(['email' => 'master@x.io', 'name' => 'From master']))->save();
    }

    public function testReadFromReplica(): void
    {
        $this->assertSame('From master', User::instance()->id(1)->value('name'));
        $this->assertSame('From replica', User::instance()->db(Model::DB_SLAVE)->id(1)->value('name'));
    }

    public function testConnectionIsInheritedByEagerLoadedRelations(): void
    {
        $user = User::instance()->db(Model::DB_SLAVE)->with('posts')->id(1)->first();
        $this->assertSame(['Replica post'], $user->posts->column('title'));
    }

    public function testModelLoadedFromReplicaWritesToReplica(): void
    {
        // Модель помнит своё соединение: save() уйдёт туда же, откуда её прочитали
        $user = User::instance()->db(Model::DB_SLAVE)->id(1)->first();
        $user->name = 'Changed';
        $user->save();

        $this->assertSame('Changed', User::instance()->db(Model::DB_SLAVE)->id(1)->value('name'));
        $this->assertSame('From master', User::instance()->id(1)->value('name'));
        // Для записи в master переключите соединение явно
        $user->db(Model::DB_MASTER)->save();
        $this->assertSame('Changed', User::instance()->id(1)->value('name'));
    }

    public function testUnknownConnectionThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('`nope` not found in db list');
        Post::instance()->db('nope');
    }

    public function testConnectionSurvivesSerialization(): void
    {
        $user = User::instance()->db(Model::DB_SLAVE)->id(1)->first();
        $copy = unserialize(serialize($user));
        $copy->name = 'Serialized';
        $copy->update('name');

        $this->assertSame('Serialized', User::instance()->db(Model::DB_SLAVE)->id(1)->value('name'));
    }
}
