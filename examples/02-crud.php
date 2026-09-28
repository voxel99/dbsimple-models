<?php

/**
 * 02. CRUD: создание, чтение, обновление, удаление.
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\Tag;
use Jam\Models\Examples\Models\User;
use Jam\Models\Model;
use Jam\Models\ModelList;

require __DIR__ . '/bootstrap.php';
$db = examples_connect();

section('INSERT: save() на модели без первичного ключа');
$user = new User(['email' => 'alice@example.com', 'name' => 'Alice', 'interests' => ['php']]);
$id = $user->save();                       // возвращает id, он же проставляется в модель
show('id', $id);
show('created_at (Timestamps)', $user->created_at);

section('SELECT: построитель начинается с Model::instance()');
$user = User::instance()->id($id)->first();   // first() на пустой выборке вернёт ПУСТУЮ модель, не null
show('user', $user->toArray());
show('User::instance()->id(404)->first()->isEmpty()', User::instance()->id(404)->first()->isEmpty());
show('findOrFail (хелпер BaseModel)', User::findOrFail($id)->name);

section('UPDATE: save() обновляет ВСЕ загруженные поля');
$user->name = 'Alice Cooper';
$user->save();

section('UPDATE только нужных колонок: update($fields)');
$user->name = 'Alice C.';
$user->update('name');                     // строка или массив полей
$user->updateByArray(['is_active' => false]); // присвоить и сохранить только эти поля

section('Частичная выборка: при сохранении пишутся только загруженные поля');
$partial = User::instance()->id($id)->first('id, name');
$partial->name = 'Alice';
$partial->save();

section('null против NULL');
// null в модели означает «значение не задано» — такое поле просто не попадёт в UPDATE.
// Чтобы записать в БД NULL, используйте Model::NULL_VALUE.
$user->name = null;
$user->update('name');
show('после name = null', User::instance()->id($id)->value('name'));
$user->name = Model::NULL_VALUE;
$user->update('name');
show('после name = NULL_VALUE', User::instance()->id($id)->value('name'));

section('Атомарный инкремент');
quietly(fn() => (new \Jam\Models\Examples\Models\Post(['title' => 'Counter', 'views' => 10]))->save());
$post = \Jam\Models\Examples\Models\Post::instance()->where('title = ?', 'Counter')->first();
$post->incByUpdate('views', 5);            // UPDATE ... SET views = views + 5
show('views', $post->views);

section('Массовая вставка одним запросом');
(new ModelList(Tag::class, [['name' => 'php'], ['name' => 'orm'], ['name' => 'sql']]))->insert();

section('UPDATE по списку id без загрузки моделей');
User::updateRaw(['is_active' => 1], [$id]);   // для моделей с Timestamps сам добавит updated_at

section('DELETE');
Tag::instance()->where('name = ?', 'sql')->first()->delete();
Tag::instance()->deleteWhere('name IN (?a)', ['orm']);   // загружает записи и удаляет по одной (с событиями)
show('оставшиеся теги', Tag::instance()->column('name'));

section('Транзакции — через объект соединения');
$db->transaction();
try {
    (new Tag(['name' => 'temporary']))->save();
    throw new RuntimeException('что-то пошло не так');
} catch (RuntimeException) {
    $db->rollback();
}
show('temporary после rollback', Tag::instance()->where('name = ?', 'temporary')->count());
