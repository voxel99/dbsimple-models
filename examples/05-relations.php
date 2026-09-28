<?php

/**
 * 05. Связи между моделями.
 *
 * Объявляются в конструкторе модели ДО parent::__construct($data):
 *
 *   $this->hasOne(Profile::class, 'profile', 'id', 'user_id');     // users.id  -> profiles.user_id
 *   $this->hasMany(Post::class,   'posts',   'id', 'user_id');     // users.id  -> posts.user_id
 *   $this->belongs(User::class,   'author',  'user_id', 'id');     // posts.user_id -> users.id
 *   $this->hasMany(Tag::class, 'tags', 'id', 'id', [], PostTag::class, 'post_id', 'tag_id'); // many-to-many
 *
 * Аргументы: класс (или Closure для полиморфных связей), алиас (имя свойства),
 * локальный ключ (в этой модели), внешний ключ (в связанной), [условия], [pivot-класс и его ключи].
 *
 * Связи НЕ загружаются лениво: обращение к $post->author без with('author') вернёт null.
 * Это осознанное решение — все запросы явные, N+1 невозможен «случайно».
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\Activity;
use Jam\Models\Examples\Models\Category;
use Jam\Models\Examples\Models\Post;
use Jam\Models\Examples\Models\Role;
use Jam\Models\Examples\Models\User;

require __DIR__ . '/bootstrap.php';
examples_connect();
examples_seed();

section('Без with() связь не загружается');
$post = Post::instance()->id(1)->first();
show('$post->author', $post->author);

section('belongs (многие-к-одному)');
$post = Post::instance()->with('author, category')->id(1)->first();
show('author', $post->author->name);
show('category', $post->category->title);

section('hasOne / hasMany');
$user = User::instance()->with('profile, posts')->id(1)->first();
show('profile->bio', $user->profile->bio);
show('posts (ModelList)', $user->posts->column('title'));
show('count($user->posts)', count($user->posts));
foreach ($user->posts as $i => $p) {
    show("posts[$i]", get_class($p) . ' #' . $p->id);
}

section('Нет связанных записей — свойство null (а не пустой список)');
$carol = User::instance()->with('posts, profile')->id(3)->first();
show('$carol->posts', $carol->posts);
show('$carol->posts?->count() ?? 0', $carol->posts?->count() ?? 0);

section('many-to-many через pivot-модель');
$posts = Post::instance()->with('tags:o(name)')->orderBy('id')->all();
foreach ($posts as $p) {
    show($p->title, $p->tags?->column('name') ?? []);
}

section('Связь модели с самой собой');
$root = Category::instance()->with('children')->where('parent_id = 0')->first();
show($root->title . ' -> children', $root->children->column('title'));
show('Slugable: getByIdOrSlug("php")', Category::getByIdOrSlug('php')->title);

section('Полиморфная связь (класс по значению колонки)');
quietly(function () {
    (new Activity(['user_id' => 2, 'action' => 'commented', 'subject_type' => 'comment', 'subject_id' => 1]))->save();
    (new Activity(['user_id' => 1, 'action' => 'published', 'subject_type' => 'post', 'subject_id' => 2]))->save();
});
$feed = Activity::instance()->with('subject, user')->orderBy('id')->all();
foreach ($feed as $a) {
    $subject = $a->subject;
    show($a->user->name . ' ' . $a->action, get_class($subject) . ': ' . ($subject->title ?? $subject->body));
}

section('Связь со справочником в коде (DummyModel) — без запроса к БД');
$users = User::instance()->with('role')->orderBy('id')->all();
foreach ($users as $u) {
    show($u->name, $u->role->title);
}
show('Role::instance()->where("code = ?", "editor")->first()->id', Role::instance()->where('code = ?', 'editor')->first()->id);
