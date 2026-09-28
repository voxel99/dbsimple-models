<?php

/**
 * 03. Построитель запросов.
 *
 * Model::instance() — «построитель»: where()/orderBy()/limit()/... копят состояние,
 * а методы выборки (first, all, collection, column, value, count, ...) выполняют запрос
 * и СБРАСЫВАЮТ состояние. Исключение — агрегаты (count/sum/...): они сбрасывают только себя,
 * чтобы можно было посчитать total и затем выбрать страницу теми же условиями.
 *
 * Условия пишутся на SQL с плейсхолдерами DbSimple:
 *   ?  — строка/число с экранированием     ?d — целое      ?f — дробное
 *   ?# — идентификатор (колонка/таблица)    ?a — массив (IN-список или SET a=1, b=2)
 *   ?s — подзапрос (Model::subquery)         {...} — блок выпадает, если значение DBSIMPLE_SKIP
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\Post;
use Jam\Models\Examples\Models\User;

require __DIR__ . '/bootstrap.php';
examples_connect();
examples_seed();

section('where() + плейсхолдеры; несколько where соединяются через AND');
$titles = Post::instance()
    ->where('user_id = ?d', 1)
    ->where('views >= ?d AND title LIKE ?', 100, '%PHP%')
    ->column('title');
show('titles', $titles);

section('IN-список');
show('ids', Post::instance()->where('id IN (?a)', [1, 3])->column('id'));

section('Необязательные условия: блок {...} выпадает при DBSIMPLE_SKIP');
$filter = ['user_id' => null, 'min_views' => 50];   // например, из query string
$count = Post::instance()->where(
    '1=1 { AND user_id = ?d } { AND views >= ?d }',
    $filter['user_id'] ?? DBSIMPLE_SKIP,
    $filter['min_views'] ?? DBSIMPLE_SKIP
)->count();
show('count', $count);

section('Скоупы — обычные статические методы модели');
show('published', Post::published()->orderBy('id')->column('title'));
show('active users', User::active()->column('email'));

section('Сортировка, limit/offset');
show('top-2 by views', Post::instance()->orderBy('views DESC')->limit(2)->column('title'));
show('сложная сортировка', Post::instance()->orderBy('user_id, views DESC')->column('id'));
show('offset без limit', Post::instance()->orderBy('id')->offset(2)->column('id'));

section('Пагинация: total + страница одними условиями');
$query = Post::instance()->where('status = ?d', Post::STATUS_PUBLISHED);
$total = $query->count();                                     // условия сохраняются
$page = $query->orderBy('id DESC')->limit(2)->offset(0)->all();   // ...и сбрасываются здесь
show('total', $total);
show('page', $page->column('title'));

section('Одиночные значения и колонки');
show('value()', Post::instance()->id(2)->value('title'));
show('column(field, key)', User::instance()->column('email', 'id'));
show('агрегаты', [
    'count' => Post::instance()->count(),
    'sum' => Post::instance()->sum('views'),
    'max' => Post::instance()->max('views'),
    'avg' => Post::instance()->avg('views'),
]);

section('GROUP BY + сырые строки (без моделей) для отчётов');
$report = Post::instance()
    ->groupBy('user_id')
    ->orderBy('user_id')
    ->collectionRaw('user_id, COUNT(*) AS posts, SUM(views) AS views');
show('report', $report);

section('JOIN: алиас таблицы + поля с префиксом');
$posts = Post::instance()
    ->alias('p')
    ->join('users u ON u.id = p.user_id')
    ->where('u.name = ?', 'Alice')
    ->orderBy('p.id')
    ->collection('p.*');                   // поля модели; колонки u.* в модель не влезут
show('posts by Alice', $posts->column('title'));

// Колонки чужих таблиц забирайте как сырые строки (collectionRaw) — у модели их нет в $fields
$rows = Post::instance()
    ->alias('p')
    ->leftJoin('users u ON u.id = p.user_id')
    ->orderBy('p.id')
    ->collectionRaw('p.id, p.title, u.name AS author');
show('rows with author', $rows);

section('Подзапрос');
$popular = Post::subquery('SELECT user_id FROM posts WHERE views > ?d', 100);
show('authors of popular posts', User::instance()->where('id IN (?s)', $popular)->column('name'));

section('chunk(): обработка больших выборок пачками');
Post::instance()->chunk(function ($list, int $i) {
    show("chunk #$i", $list->column('id'));
}, 2, null, 'id');

section('Персистентный построитель: условия НЕ сбрасываются');
$byBob = Post::instance(true)->where('user_id = ?d', 2);
show('count', $byBob->count());
show('titles', $byBob->column('title'));
show('снова count', $byBob->count());
