<?php

namespace Jam\Models;

interface ScalarableInterface
{
    /**
     * @return Model
     */
    public static function fromScalar($value);

    /**
     * @param Model $object
     * @return mixed
     */
    public static function toScalar($object);
}
