<?php

namespace Jam\Models\Traits;

use Exception;
use Jam\Models\IntableInterface;
use Jam\Models\ModelAbstract;
use Jam\Models\StringableInterface;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Utils\Code;

trait Intable
{
    public function bootIntable()
    {
        if (false == ($this instanceof IntableInterface)) {
            throw new Exception(sprintf("Model %s must implements 'IntableInterface'", __CLASS__));
        }
    }

    public static function intsType($type)
    {
        $ints = static::ints();
        if (!isset($ints[$type])) {
            throw new Exception(sprintf("Wrong type in intsType: %s", $type));
        }
        return $ints[$type];
    }

    public static function toInt($type, $value)
    {
        $intsType = static::intsType($type);
        if (!isset($intsType[$value])) {
            throw new Exception(sprintf("Ints type '%s' for value '%s' not found. All values: [%s]", $type, $value, array_to_description($intsType)));
        }
        return $intsType[$value];
    }

    public static function fromInt($type, $value, $default = null)
    {
        $flipped = array_flip(self::intsType($type));
        return $flipped[$value] ?? $default;
    }
}
