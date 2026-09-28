<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\Storage\Criteria;
use Jam\Models\Tests\Fixtures\Models\Note;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Fixtures\Models\UserWithNotes;
use Jam\Models\Tests\Support\ArrayStorage;
use Jam\Models\Tests\Support\DatabaseTestCase;

/**
 * Модель на своём (не-SQL) хранилище: тот же API, что у SQL-моделей, и связи между хранилищами.
 */
final class CustomStorageTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ArrayStorage::reset();
    }

    public function testCrudWithArrayConditions(): void
    {
        $id = (new Note(['user_id' => 1, 'text' => 'Buy milk', 'tags' => ['home'], 'pinned' => true]))->save();
        (new Note(['user_id' => 1, 'text' => 'Write tests']))->save();
        (new Note(['user_id' => 2, 'text' => 'Call Bob']))->save();

        $this->assertSame(1, $id);
        $this->assertSame([], $this->queries(), 'no SQL for a custom storage');

        $note = Note::instance()->id(1)->first();
        $this->assertSame('Buy milk', $note->text);
        $this->assertSame(['home'], $note->tags);
        $this->assertTrue($note->pinned);
        $this->assertNotEmpty($note->created_at);

        $this->assertSame(['Write tests', 'Buy milk'], Note::instance()->where(['user_id' => 1])->orderBy('id DESC')->column('text'));
        $this->assertSame(['Call Bob'], Note::instance()->where(['text' => ['like' => 'call%']])->column('text'));
        $this->assertSame(2, Note::instance()->where([Criteria::OR => [['user_id' => 2], ['pinned' => 1]]])->count());
        $this->assertSame(3, Note::instance()->max('id'));

        $note->text = 'Buy oat milk';
        $note->save();
        $this->assertSame('Buy oat milk', Note::instance()->id(1)->value('text'));
        $this->assertNotEmpty(Note::instance()->id(1)->value('updated_at'));

        Note::instance()->id(2)->first()->delete();
        $this->assertSame([1, 3], Note::instance()->column('id'));
    }

    public function testSqlConditionsAreRejected(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('supports only array conditions');
        Note::instance()->where('user_id = ?d', 1)->all();
    }

    public function testEagerLoadingAcrossStorages(): void
    {
        (new User(['email' => 'alice@x.io', 'name' => 'Alice']))->save();
        (new User(['email' => 'bob@x.io', 'name' => 'Bob']))->save();
        (new Note(['user_id' => 1, 'text' => 'first']))->save();
        (new Note(['user_id' => 1, 'text' => 'second']))->save();
        (new Note(['user_id' => 2, 'text' => 'third']))->save();
        $this->resetQueryLog();

        // SQL -> custom: один SQL-запрос за пользователями, заметки — из своего хранилища
        $users = UserWithNotes::instance()->with('notes:o(id DESC):c')->orderBy('id')->all();
        $this->assertQueryCount(1);
        $this->assertSame(['second', 'first'], $users[0]->notes->column('text'));
        $this->assertSame('third', $users[1]->toArray()['notes'][0]['preview']);

        // custom -> SQL: заметки из своего хранилища, авторы — одним SQL-запросом
        $this->resetQueryLog();
        $notes = Note::instance()->with('author(id, name)')->orderBy('id')->all();
        $this->assertSame(['SELECT * FROM users WHERE `id` IN (1, 2)'], $this->queries());
        $this->assertSame(['Alice', 'Alice', 'Bob'], array_map(fn($n) => $n->author->name, iterator_to_array($notes)));
    }

    public function testSavingGraphAcrossStorages(): void
    {
        $user = new UserWithNotes(['email' => 'alice@x.io', 'notes' => [['text' => 'a'], ['text' => 'b']]]);
        $user->save();

        $this->assertSame(1, User::instance()->count());
        $this->assertSame([1, 1], Note::instance()->column('user_id'));

        // SAVE_USE_CHECK_OLD удаляет отвязанные записи и в чужом хранилище
        $user = UserWithNotes::instance()->with('notes')->id(1)->first();
        $user->notes = [$user->notes[0]];
        $user->save([Model::SAVE_USE_CHECK_OLD => true]);
        $this->assertSame(['a'], Note::instance()->column('text'));
    }

    public function testModelWorksWithoutSqlConnection(): void
    {
        Model::initDbSimple([]);
        (new Note(['text' => 'offline']))->save();
        $this->assertSame(['offline'], Note::instance()->column('text'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Db not initialized');
        User::instance()->count();
    }
}
