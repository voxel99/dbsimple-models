<?php

namespace Jam\Models\Traits;

use Jam\Models\Utils\ArrayHelper;

trait Collection
{
    public function arr()
    {
        return $this->toArray([self::FLAG_DEPENDENCIES => false, self::FLAG_EMPTY => true, self::FLAG_HIDDEN => true]);
    }

    public function keyBy($keys)
    {
        return ArrayHelper::keyBy($this, $keys);
    }

    public function pluck($value, $key = null)
    {
        return ArrayHelper::pluck($this->arr(), $value, $key);
    }
}
