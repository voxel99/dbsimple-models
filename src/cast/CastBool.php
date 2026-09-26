<?php

namespace Jam\Models\Cast;

class CastBool extends CastClosure
{
    public function dbValue($value)
    {
        return $value ? 1 : 0;
    }

    public function externalValue($value)
    {
        return (bool) $value;
    }

    public function checkValue($value)
    {
        return is_bool($value)
            ? CastClosure::CAST_EXTERNAL
            : CastClosure::CAST_DB;
    }
}
