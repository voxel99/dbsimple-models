<?php

namespace Jam\Models\Traits;

use Jam\Models\Storage\Criteria;

trait Slugable
{
    protected string $columnSlug = 'slug';

    public function findByIdOrSlug($value)
    {
        return $this->idOrSlug($value)->first();
    }

    public function findSlugById($id)
    {
        return $this->id($id)->value($this->columnSlug);
    }

    public function findIdBySlug($slug)
    {
        return $this->where([$this->columnSlug => $slug])->value($this->pk());
    }

    /**
     * @return $this
     */
    public function idOrSlug($value)
    {
        $prefix = $this->alias ? $this->alias . '.' : '';
        $values = array_values((array) $value);
        return $this->where([Criteria::OR => [
            [$prefix . $this->pk() => $values],
            [$prefix . $this->columnSlug => $values],
        ]]);
    }

    public static function getByIdOrSlug($value)
    {
        return static::instance()->findByIdOrSlug($value);
    }

    public static function getIdBySlug($slug)
    {
        return static::instance()->findIdBySlug($slug);
    }

    public static function getSlugById($id)
    {
        return static::instance()->findSlugById($id);
    }
}
