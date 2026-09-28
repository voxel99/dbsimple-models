<?php

/**
 * 08. Отдача данных наружу: toArray()/toObject() и флаги.
 *
 * Флаги — строка ':cdh...' или массив [Model::FLAG_* => true|'поля'].
 *   d — связи (включён по умолчанию, ТОЛЬКО если флаги не переданы вовсе: toArray())
 *   c — computed, h — hidden, x — extra, e — выводить null-поля
 *   s — Stringable: числовые коды -> строки ('status' => 'published')
 *   _ — «как в БД» (без кастов), a — Scalarable, t/i — заголовки/идентификаторы
 * Флаги c не наследуются связями: для связи их задают в with('rel:c').
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\Post;
use Jam\Models\Examples\Models\User;
use Jam\Models\Model;

require __DIR__ . '/bootstrap.php';
examples_connect(false);
examples_seed();

$post = Post::instance()->with('author(id, name), tags(id, name), comments[is_approved = 1](id, body):o(id DESC)')->id(2)->first();

section('toArray() по умолчанию: поля + загруженные связи, касты применены');
show('post', $post->toArray());

section("toArray(':cds') — + computed, строковые коды (status: published)");
$arr = $post->toArray(':cds');
show('status / excerpt', [$arr['status'], $arr['excerpt']]);

section('Передали флаги без d — связи пропали');
show('keys', array_keys($post->toArray(':c')));

section('Ограничить поля (связи остаются при флаге d)');
show('fields', $post->toArray(null, 'id, title'));
show('fields без связей', $post->toArray([], 'id, title'));
show('exclude', array_keys($post->toArray(null, null, null, ['body', 'flags'])));

section('Флаги для связи задаются при загрузке');
$user = User::instance()->with('posts(id, title, body):c:o(id)')->id(1)->first();
show('posts[0]', $user->toArray()['posts'][0]);

section('Hidden-поля — только явно');
quietly(fn() => User::instance()->id(1)->first()->setPassword('secret')->save([], false));
$user = User::instance()->id(1)->first();
show('без флага', array_key_exists('password_hash', $user->toArray()));
show("':h'", array_key_exists('password_hash', $user->toArray(':h')));

section('toObject(): вложенные stdClass (удобно для json_encode и шаблонов)');
$obj = $post->toObject();
show('$obj->author->name', $obj->author->name);
show('$obj->tags[0]->name', $obj->tags[0]->name);

section('Коллекция: ModelList::toArray() / column() / pluck() / keyBy()');
$posts = Post::published()->orderBy('id')->all();
show('column(title)', $posts->column('title'));
show('pluck(title, id)', $posts->pluck('title', 'id'));
show('keyBy(id) keys', array_keys($posts->keyBy('id')));

section('JSON-ответ API');
echo json_encode(
    ['data' => Post::published()->with('author(id, name)')->orderBy('id')->limit(2)->all()->toArray(':ds')],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
), "\n";
