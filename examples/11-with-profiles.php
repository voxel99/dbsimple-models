<?php

/**
 * 11. With-профили: декларативное описание того, какие данные подгружаются.
 *
 * with() — это не набор вызовов, а строка-описание графа данных. Поэтому её удобно
 * собирать функцией с параметрами: модель знает, ЧТО у неё можно подгрузить,
 * а вызывающий код параметрами решает, что нужно ИМЕННО СЕЙЧАС:
 *
 *   - список постов — автор и теги;
 *   - страница поста — ещё комментарии с авторами, категория, профиль автора;
 *   - залогиненный пользователь — условия зависят от него (свои неодобренные комментарии);
 *   - посты внутри пользователя/ленты — тот же профиль, прикреплённый к другому пути.
 *
 * Профили см. в Models/Post.php и Models/User.php (commonWithString()).
 * Каждая связь — по-прежнему один запрос на всю выборку.
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\Activity;
use Jam\Models\Examples\Models\Comment;
use Jam\Models\Examples\Models\Post;
use Jam\Models\Examples\Models\User;
use Jam\Models\Utils\ArrayHelper;

require __DIR__ . '/bootstrap.php';
examples_connect();
examples_seed();

section('Профиль — просто строка, её видно целиком');
show('список постов', Post::commonWithString());
show('страница поста для пользователя #3', Post::commonWithString(viewerId: 3, comments: true, commentsDeep: true, full: true));

section('Список постов: автор и теги');
$posts = Post::published()->with(Post::commonWithString())->orderBy('id')->all();
show('post #1', ArrayHelper::cutFields($posts[0]->toArray(), ['title', 'author', 'tags']));

section('Страница поста глазами гостя: только одобренные комментарии');
$post = Post::instance()->with(Post::commonWithString(comments: true))->id(2)->first();
show('comments', $post->toArray()['comments']);   // [] благодаря !, а не null

section('Та же страница глазами автора неодобренного комментария (#3): видит и его');
$post = Post::instance()->with(Post::commonWithString(viewerId: 3, comments: true, commentsDeep: true, full: true))->id(2)->first();
show('post #2', ArrayHelper::cutFields($post->toArray(':cd'), ['title', 'excerpt', 'author', 'category', 'comments']));

section('Тот же профиль, прикреплённый к другому пути: посты пользователя');
// User::commonWithString() сам вызывает Post::commonWithString('posts[status = 1]:o(id DESC)')
show('with', User::commonWithString(posts: true));
$user = User::instance()->with(User::commonWithString(posts: true))->id(1)->first();
show('alice', ArrayHelper::cutFields($user->toArray(), ['name', 'profile', 'role']));
foreach ($user->posts as $userPost) {
    show($userPost->title, ['author' => $userPost->author->name, 'tags' => $userPost->tags->column('name')]);
}

section('...и ещё глубже: профиль пользователя от автора комментария');
$comments = Comment::instance()->with(User::commonWithString('author', posts: true))->orderBy('id')->all();
show('автор первого комментария и его посты', [
    'author' => $comments[0]->author->name,
    'posts' => $comments[0]->author->posts?->column('title'),
]);

section('Полиморфная связь: ? — связь есть не у всех классов');
// subject — Post или Comment. tags есть только у Post: без ? на Comment было бы исключение.
$feed = [
    ['user_id' => 1, 'action' => 'published', 'subject_type' => 'post', 'subject_id' => 2],
    ['user_id' => 2, 'action' => 'commented', 'subject_type' => 'comment', 'subject_id' => 1],
];
quietly(fn() => array_map(fn($row) => (new Activity($row))->save(), $feed));
$activities = Activity::instance()
    ->with('user(id, name), subject.author(id, name), subject.tags?:o(name)')
    ->orderBy('id')
    ->all();
foreach ($activities as $activity) {
    show($activity->action, ArrayHelper::cutFields($activity->toArray()['subject'], ['id', 'title', 'body', 'author', 'tags']));
}

section('Как устроен префикс: ArrayHelper::extendsWithName()');
show('обычный путь', ArrayHelper::extendsWithName(['author', 'tags'], 'posts'));
show('условие/поля к самому узлу', ArrayHelper::extendsWithName(['[status = 1]', '(id, title)'], 'posts'));
