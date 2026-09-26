<?php

namespace Jam\Models;

interface CasesInterface
{
    public static function cases();
    public static function getCase($prop, $value, $case, $singular = true);
}
