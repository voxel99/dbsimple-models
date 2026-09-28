<?php

/**
 * 06. Жадная загрузка: синтаксис with().
 *
 *   alias[условие](поля)^исключаемые^?!:флаги.вложенная_связь, другая_связь
 *
 *   [условие]  — SQL-условие для связанной таблицы; this(field) — значение поля родителя
 *   (поля)     — какие колонки выбрать (ключи связей добавляются автоматически)
 *   ^поля^     — какие поля убрать из результата toArray()
 *   ?          — связь необязательна: неизвестный алиас не бросает исключение
 *   !          — в toArray() вернуть {} / [] вместо отсутствующей связи
 *   :флаги     — c (computed), o(сортировка), l(лимит), f(смещение), k(ключ для keyBy),
 *                p (pivot-строки), r (вместе с soft-deleted), t, s, ... Аргументы с запятой: o(a DESC|b)
 *
 * Каждая связь — один запрос на всю выборку (WHERE fk IN (...)), независимо от числа строк.
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\Post;
use Jam\Models\Examples\Models\User;
use Jam\Models\Model;

require __DIR__ . '/bootstrap.php';
examples_connect();
examples_seed();

section('Антипаттерн N+1: запрос на каждую строку');
foreach (Post::instance()->orderBy('id')->all() as $post) {
    User::instance()->id($post->user_id)->value('name');
}

section('То же через with(): ровно 2 запроса');
foreach (Post::instance()->with('author')->orderBy('id')->all() as $post) {
    $post->author->name;
}

section('Вложенные связи и несколько путей');
$user = User::instance()->with('posts.comments.author, posts.tags, profile')->id(1)->first();
show('комментарии к первому посту', $user->posts[0]->comments->column('body'));
show('авторы комментариев', array_map(fn($c) => $c->author->name, iterator_to_array($user->posts[0]->comments)));

section('Поля, условия, исключения');
$posts = Post::instance()
    ->with('comments[is_approved = 1](id, body)^user_id^, author(id, name)')
    ->orderBy('id')
    ->all();
show('post #1', $posts[0]->toArray());

section('Условие со ссылкой на родителя: this(field)');
// Комментарии, оставленные НЕ автором поста
$posts = Post::instance()->with('comments[user_id <> this(user_id)]')->orderBy('id')->all();
show('post #1 foreign comments', $posts[0]->comments?->column('body'));

section('Флаги: сортировка, keyBy, computed');
$user = User::instance()->with('posts(id, title, body):o(views DESC):k(id):c')->id(1)->first();
show('posts keyed by id', $user->toArray()['posts']);

section('l(N) — лимит на ВЕСЬ запрос связи, а не «N на родителя»');
$users = User::instance()->with('posts:o(id):l(1)')->orderBy('id')->all();
show('Alice', $users[0]->posts?->column('title'));
show('Bob (ничего не досталось)', $users[1]->posts);

section('! — пустые значения вместо отсутствующих связей в toArray()');
$carol = User::instance()->with('profile!, posts!')->id(3)->first();
show('carol', $carol->toArray());

section('Soft-deleted записи в связях скрыты; :r — показать');
quietly(fn() => Post::instance()->id(2)->first()->delete());
show('posts', User::instance()->with('posts')->id(1)->first()->posts->column('id'));
show('posts:r', User::instance()->with('posts:r')->id(1)->first()->posts->column('id'));

section('Массивная форма with() — удобно собирать программно');
$with = ['comments' => ['fields' => ['id', 'body'], 'where' => ['is_approved = 1'], 'flags' => [Model::FLAG_ORDER => 'id DESC']]];
show('comments', Post::instance()->with($with)->id(1)->first()->comments->column('body'));

section('with() на построителе живёт между запросами; without() убирает связи');
$builder = Post::instance()->with('author, comments');
$builder->without('comments');
show('with', array_keys($builder->getWithArray()));
