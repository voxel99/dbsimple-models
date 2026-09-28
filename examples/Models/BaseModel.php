<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

use Jam\Models\Model;

/**
 * Базовая модель проекта.
 *
 * Best practice: не наследуйте прикладные модели напрямую от Jam\Models\Model —
 * заведите свой базовый класс. Это единая точка для:
 *  - источника времени (Timestamps / SoftDeletes / updateRaw используют freshTimestamp());
 *  - общих хелперов (findOrFail и т.п.);
 *  - соглашений проекта (например, префикса таблиц).
 */
abstract class BaseModel extends Model
{
    public static function freshTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * first() никогда не возвращает null — на пустой выборке вернётся пустая модель.
     * Хелпер делает отсутствие записи явным.
     */
    public static function findOrFail(int|string $id): static
    {
        $model = static::instance()->id($id)->first();
        if ($model->isEmpty()) {
            throw new \RuntimeException(sprintf('%s #%s not found', static::class, $id));
        }
        return $model;
    }
}
