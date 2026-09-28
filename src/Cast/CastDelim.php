<?php

namespace Jam\Models\Cast;

class CastDelim extends CastClosure
{
    public function dbValue($value)
    {
        $delim = $this->rule ?: ',';
        return implode($delim, $value);
    }

    public function externalValue($value)
    {
        $delim = $this->rule ?: ',';
        return explode($delim, (string) $value);
    }

    public function checkValue($value)
    {
        return is_string($value)
            ? CastClosure::CAST_DB
            : CastClosure::CAST_EXTERNAL;
    }
}
