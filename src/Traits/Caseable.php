<?php

namespace Jam\Models\Traits;

use Exception;
use Jam\Models\CasesInterface;

trait Caseable
{
    public function bootCaseable()
    {
        if (false === ($this instanceof CasesInterface)) {
            throw new Exception(sprintf("Model %s must implements 'CasesInterface'", __CLASS__));
        }
    }

    public static function getCase($prop, $value, $case, $singular = true)
    {
        $cases = static::cases();
        return $cases[$prop][$value][$singular ? 0 : 1][$case];
    }
}
