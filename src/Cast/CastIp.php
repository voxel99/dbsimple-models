<?php

namespace Jam\Models\Cast;

use Jam\Models\Utils\Ip;

class CastIp extends CastClosure
{
    public function dbValue($value)
    {
        return $value ? Ip::pton($value) : "";
    }

    public function externalValue($value)
    {
        return $value ? Ip::ntop($value) : "";
    }

    public function checkValue($value)
    {
        return Ip::validate($value)
            ? CastClosure::CAST_EXTERNAL
            : CastClosure::CAST_DB;
    }
}
