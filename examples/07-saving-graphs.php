<?php

/**
 * 07. Сохранение связанных моделей одним save().
 *
 * Порядок внутри save():
 *   1. belongs-связи (родители) — чтобы получить их id и проставить внешние ключи в модель;
 *   2. сама модель (INSERT или UPDATE);
 *   3. has-связи (дети) — им проставляется внешний ключ = id модели;
 *   4. many-to-many — связанные модели и строки pivot-таблицы (INSERT IGNORE).
 * Сохраняются только связи, присутствующие в модели (загруженные через with() или присвоенные).
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\Category;
use Jam\Models\Examples\Models\Comment;
use Jam\Models\Examples\Models\Post;
use Jam\Models\Examples\Models\PostTag;
use Jam\Models\Examples\Models\Tag;
use Jam\Models\Examples\Models\User;
use Jam\Models\Model;

require __DIR__ . '/bootstrap.php';
examples_connect();

section('Граф из вложенных массивов: пользователь + профиль + посты + комментарии');
$user = new User([
    'email' => 'alice@example.com',
    'name' => 'Alice',
    'profile' => ['bio' => 'Hello!'],
    'posts' => [
        ['title' => 'First post'],
        ['title' => 'Second post', 'comments' => [['body' => 'Nice!']]],
    ],
]);
$user->save();
show('внешние ключи проставлены и в памяти', [
    'profile.user_id' => $user->profile->user_id,
    'posts[1].user_id' => $user->posts[1]->user_id,
    'posts[1].comments[0].post_id' => $user->posts[1]->comments[0]->post_id,
]);

section('belongs: родитель сохраняется первым');
$post = new Post([
    'title' => 'Post in a new category',
    'category' => ['title' => 'Databases', 'slug' => 'databases'],
    'user_id' => $user->id,   // для существующего родителя достаточно ключа
]);
$post->save();
show('category_id / user_id', [$post->category_id, $post->user_id]);

section('Осторожно: save() рекурсивно сохраняет ВСЕ загруженные связи');
// $user всё ещё держит в памяти profile, posts и comments — присвоив его как author,
// мы заставим save() перезаписать весь этот граф (смотрите на UPDATE-ы ниже).
$draft = new Post(['title' => 'Draft', 'author' => $user]);
$draft->save();
// Если связи загружены «для чтения», сохраняйте без них: $model->save([], false)

section('many-to-many: связанные модели + pivot');
$post->tags = [['name' => 'sql'], ['name' => 'orm']];
$post->save();
show('post_tag', PostTag::instance()->collectionRaw('post_id, tag_id'));

section('Повторное сохранение не дублирует pivot-строки');
$post = Post::instance()->with('tags')->id($post->id)->first();
$post->title = 'Renamed';
$post->save();
show('post_tag count', PostTag::instance()->count());

section('SAVE_USE_CHECK_OLD: удалить связи, которых больше нет в модели');
$post->tags = array_values(array_filter($post->tags->toArray(), fn($t) => $t['name'] !== 'orm'));
$post->save([Model::SAVE_USE_CHECK_OLD => true]);
show('теги поста', Post::instance()->with('tags')->id($post->id)->first()->tags->column('name'));
show('сам тег не удалён, удалена только строка pivot', Tag::instance()->column('name'));

section('То же для hasMany: удаляются сами дочерние записи');
$second = Post::instance()->with('comments')->where('title = ?', 'Second post')->first();
quietly(fn() => (new Comment(['post_id' => $second->id, 'body' => 'Another']))->save());
$second = Post::instance()->with('comments')->id($second->id)->first();
$second->comments = [$second->comments[0]];    // оставить только первый ([] — удалить все)
$second->save([Model::SAVE_USE_CHECK_OLD => true]);
show('комментарии', Comment::instance()->where('post_id = ?d', $second->id)->column('body'));

section('Сохранить только саму модель, без связей');
$user = User::instance()->with('posts')->id(1)->first();
$user->name = 'Alice (renamed)';
$user->save([], false);                        // второй аргумент — saveRelations

section('Каскадное удаление: delete(true) удаляет загруженные has-связи');
$user = User::instance()->with('profile, posts')->id(1)->first();
$user->delete(true);
show('профилей осталось', \Jam\Models\Examples\Models\Profile::instance()->count());
show('постов (SoftDeletes: помечены удалёнными)', Post::instance()->count() . ' / withTrashed: ' . Post::instance()->withTrashed()->count());
show('категорий (belongs не удаляются без 2-го аргумента)', Category::instance()->count());
