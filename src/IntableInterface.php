<?php

namespace Jam\Models;

interface IntableInterface
{
    public static function ints();
    public static function toInt($type, $value);
    public static function fromInt($type, $value, $default = null);
}
