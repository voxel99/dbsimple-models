<?php

namespace Jam\Models\Traits;

trait Slugable
{
    protected string $columnSlug = 'slug';

    public function findByIdOrSlug($value)
    {
        return $this->where('?#=? OR ?#=?', $this->pk(), $value, $this->columnSlug, $value)->first();
    }

    public function findSlugById($id)
    {
        return $this->id($id)->value($this->columnSlug);
    }

    public function findIdBySlug($slug)
    {
        return $this->where('?# = ?', $this->columnSlug, $slug)->value($this->pk());
    }

    /**
     * @return $this
     */
    public function idOrSlug($value)
    {
        return $this->where(
            sprintf(
                '(%s?# IN (?a)) OR (%s?# IN (?a))',
                $this->alias ? $this->alias . "." : "",
                $this->alias ? $this->alias . "." : "",
            ),
            $this->pk(),
            (array) $value,
            $this->columnSlug,
            (array) $value
        );
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
