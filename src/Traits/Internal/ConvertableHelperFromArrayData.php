<?php

namespace Jam\Models\Traits\Internal;

class ConvertableHelperFromArrayData
{
    public $className = '';
    public $classArgs = [];
    public $castToArray = false;
    public $v;

    public function __construct($v)
    {
        $this->v = $v;
    }
}
