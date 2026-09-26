<?php

namespace Jam\Models\Cast;

use Jam\Models\Model;

class CastFlags extends CastClosure
{
    private static function getBitNumber($bitNumber)
    {
        if (strpos($bitNumber, "-") !== false) {
            list($min, $max) = explode('-', $bitNumber);
            $min = (int)trim($min);
            $max = (int)trim($max);
            if ($min > $max) {
                $t = $max;
                $max = $min;
                $min = $t;
            }
        } else {
            $min = $bitNumber;
            $max = $bitNumber;
        }
        return [$min, $max];
    }

    public static function expandFlags($value, $flagsMap, $useStringValues = false)
    {
        $ret = [];
        foreach ($flagsMap as $flagName => $bitNumber) {
            list($min, $max) = static::getBitNumber($bitNumber);
            $mask = pow(2, ($max - $min + 1)) - 1;
            $flagValue = ($value >> $min) & $mask;
            if ($min === $max) {
                if ($useStringValues) {
                    $ret[$flagName] = $flagValue ? Model::VALUE_YES : Model::VALUE_NO;
                } else {
                    $ret[$flagName] = $flagValue ? 1 : 0;
                }
            } else {
                $ret[$flagName] = $flagValue;
            }
        }
        return $ret;
    }

    public static function collapseFlags($flagsArray, $flagsMap)
    {
        if (is_object($flagsArray)) {
            $flagsArray = (array)$flagsArray;
        }
        $value = 0;
        foreach ($flagsMap as $flagName => $bitNumber) {
            list($min, $max) = static::getBitNumber($bitNumber);
            $mask = pow(2, ($max - $min + 1)) - 1;
            if (!empty($flagsArray[$flagName]) && ($flagsArray[$flagName] !== Model::VALUE_NO)) {
                $intValue = ($flagsArray[$flagName] === Model::VALUE_YES || $flagsArray[$flagName] === 1) ? 1 : (int)$flagsArray[$flagName];
                $value |= ($mask & $intValue) << $min;
            }
        }
        return $value;
    }

    public function dbValue($value)
    {
        return static::collapseFlags($value, $this->rule);
    }

    public function externalValue($value)
    {
        return (object)static::expandFlags($value, $this->rule);
    }

    public function stringValue($value)
    {
        $dbValue = $value;
        if ($this->checkValue($value) === self::CAST_EXTERNAL) {
            $dbValue = $this->dbValue($value);
        }
        return (object)static::expandFlags($dbValue, $this->rule, true);
    }

    public function checkValue($value)
    {
        return is_string($value) || is_numeric($value)
            ? CastClosure::CAST_DB
            : CastClosure::CAST_EXTERNAL;
    }
}
