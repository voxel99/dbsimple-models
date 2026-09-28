<?php

/**
 * 09. Жизненный цикл: события, Timestamps, SoftDeletes.
 *
 * События вешаются на КОНКРЕТНЫЙ экземпляр: $model->on(Model::EVENT_*, callable).
 * Постоянные обработчики объявляют в трейте с методом boot{ИмяТрейта}() — он вызывается
 * в конструкторе каждой модели, использующей трейт (так устроены Timestamps и SoftDeletes).
 *
 *   creating(array $row): array|false        — можно изменить строку или отменить INSERT
 *   created($id, array $row)
 *   updating($id, array $row): array          — Model::makeNotUpdateble($row) отменяет UPDATE
 *   updated($id, array $row)
 *   deleting(Model $m): Model|false            — false отменяет DELETE
 *   deleted(Model $m)
 *   retrieved(array $rows): array              — строки из БД до создания моделей
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\Post;
use Jam\Models\Examples\Models\Tag;
use Jam\Models\Model;

require __DIR__ . '/bootstrap.php';
examples_connect();

/** Переиспользуемое поведение — трейт с boot-методом: нормализует имя тега перед записью. */
trait NormalizesName
{
    public function bootNormalizesName(): void
    {
        $normalize = function (array $row): array {
            if (isset($row['name'])) {
                $row['name'] = mb_strtolower(trim($row['name']));
            }
            return $row;
        };
        $this->on(Model::EVENT_CREATING, $normalize);
        $this->on(Model::EVENT_UPDATING, fn($id, array $row) => $normalize($row));
    }
}

final class NormalizedTag extends Tag
{
    use NormalizesName;
}

section('Поведение через трейт с boot-методом');
(new NormalizedTag(['name' => '  PHP ']))->save();

section('Обработчики на экземпляре');
$tag = new Tag(['name' => 'orm']);
$tag->on(Model::EVENT_CREATED, function ($id, array $row) {
    echo "  -> created #$id\n";
    return $row;
});
$tag->save();

section('Отмена операций');
$tag = new Tag(['name' => 'forbidden']);
$tag->on(Model::EVENT_CREATING, fn(array $row) => false);
show('save() вернул', $tag->save());

$tag = Tag::instance()->where('name = ?', 'orm')->first();
$tag->on(Model::EVENT_DELETING, fn() => false);
$tag->delete();
show('orm всё ещё в БД', Tag::instance()->where('name = ?', 'orm')->count());

section('retrieved: обработка строк до создания моделей');
$builder = Tag::instance();
$builder->on(Model::EVENT_RETRIEVED, function (array $rows): array {
    foreach ($rows as &$row) {
        $row['name'] = '#' . $row['name'];
    }
    return $rows;
});
show('names', $builder->all()->column('name'));

section('Timestamps: created_at при вставке, updated_at при обновлении');
$post = new Post(['title' => 'Timestamps']);
$post->save();
show('created_at / updated_at', [$post->created_at, $post->updated_at]);
$post->title = 'Timestamps (edited)';
$post->save();
show('updated_at', $post->updated_at);

section('SoftDeletes: delete() помечает запись, выборки её не видят');
$post->delete();
show('trashed()', $post->trashed());
show('count()', Post::instance()->count());
show('withTrashed()->count()', Post::instance()->withTrashed()->count());
show('только удалённые', Post::instance()->where('deleted_at IS NOT NULL')->column('title'));

section('restore() и forceDelete()');
$trashed = Post::instance()->withTrashed()->id($post->id)->first();
$trashed->restore();
show('после restore count()', Post::instance()->count());
$trashed->forceDelete();
show('после forceDelete withTrashed()->count()', Post::instance()->withTrashed()->count());

section('Время для Timestamps/SoftDeletes берётся из static::freshTimestamp()');
echo "  Переопределите его в базовой модели (см. Models/BaseModel.php), например для тестов.\n";
