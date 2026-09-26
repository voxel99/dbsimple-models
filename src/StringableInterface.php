<?php

namespace Jam\Models;

interface StringableInterface
{
    /**
     * @return array<string, array<int|string, string>>
     */
    public static function strings();

    public static function toString($type, $value);

    public static function fromString($type, $value, $default = null);

    public static function hasString($type, $string, $positiveKeysOnly = null): bool;

    public static function stringValueIfExists($type, $value);
}
