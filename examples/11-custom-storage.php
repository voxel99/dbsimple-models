<?php

/**
 * 11. Своё хранилище: модель не на SQL.
 *
 * Модель отвечает за поля, типы, связи, события и toArray(), а чтение/запись строк делегирует
 * хранилищу (Jam\Models\Storage\StorageInterface):
 *
 *   select(Model, Query)                 — строки по условиям, сортировке, limit/offset
 *   aggregate(Model, Query, func, field) — COUNT/SUM/AVG/MIN/MAX
 *   insert / update / increment / delete
 *
 * По умолчанию используется SqlStorage (DbSimple), для DummyModel — MemoryStorage.
 * Своё хранилище подключается переопределением createStorage(). Так же будет подключаться
 * MongoDB: хранилище переводит Criteria в фильтр Mongo.
 *
 * Здесь — хранилище в памяти из тестов (tests/Support/ArrayStorage.php, ~130 строк).
 * Ограничение: такие модели понимают только условия-массивы; SQL-фрагменты, JOIN и
 * GROUP BY работают только в SQL-хранилище.
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\BaseModel;
use Jam\Models\Examples\Models\User;
use Jam\Models\Model;
use Jam\Models\Storage\Criteria;
use Jam\Models\Storage\StorageInterface;
use Jam\Models\Tests\Support\ArrayStorage;
use Jam\Models\Traits\Timestamps;

require __DIR__ . '/bootstrap.php';
examples_connect();
examples_seed();

/** Заметка хранится не в SQL, но связана с SQL-моделью User */
final class Note extends BaseModel
{
    use Timestamps;

    protected $table = 'notes';
    protected string $fields = 'id, user_id, text, tags, created_at, updated_at';
    protected $types = ['int' => 'id, user_id', 'json' => 'tags'];

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'author', 'user_id', 'id');
        parent::__construct($data);
    }

    protected function createStorage(): StorageInterface
    {
        return new ArrayStorage();
    }
}

/** SQL-модель, у которой есть связь на модель из другого хранилища */
final class UserWithNotes extends User
{
    public function __construct($data = null)
    {
        $this->hasMany(Note::class, 'notes', 'id', 'user_id');
        parent::__construct($data);
    }
}

section('Тот же API: save / where / orderBy / count — без SQL');
(new Note(['user_id' => 1, 'text' => 'Refactor storage layer', 'tags' => ['work']]))->save();
(new Note(['user_id' => 1, 'text' => 'Buy milk', 'tags' => ['home']]))->save();
(new Note(['user_id' => 2, 'text' => 'Review PR']))->save();
show('notes of Alice', Note::instance()->where(['user_id' => 1])->orderBy('id DESC')->column('text'));
show('count (user 2 OR text like %milk%)', Note::instance()->where([Criteria::OR => [['user_id' => 2], ['text' => ['like' => '%milk%']]]])->count());

section('Связь «своё хранилище -> SQL»: авторы одним SQL-запросом');
foreach (Note::instance()->with('author(id, name)')->orderBy('id')->all() as $note) {
    show($note->text, $note->author->name);
}

section('Связь «SQL -> своё хранилище»: заметки без SQL');
$users = UserWithNotes::instance()->with('notes:o(id)')->where(['id' => [1, 2]])->orderBy('id')->all();
foreach ($users as $user) {
    show($user->name, $user->notes?->column('text') ?? []);
}

section('Сохранение графа через границу хранилищ');
$carol = UserWithNotes::instance()->id(3)->first();
$carol->notes = [['text' => 'Hello from Carol']];
$carol->save();
show('notes of Carol', Note::instance()->where(['user_id' => 3])->column('text'));

section('SQL-условие в модели не на SQL — понятная ошибка');
try {
    Note::instance()->where('user_id = ?d', 1)->all();
} catch (\Jam\Models\ModelException $e) {
    show('ModelException', $e->getMessage());
}
