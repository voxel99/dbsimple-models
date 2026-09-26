<?php

namespace Jam\Models\Utils;

use Closure;
use stdClass;

/**
 * Вспомогательный класс, содержащий функции для работы с массивами
 */
class ArrayHelper
{
    private static $explodedValues = [];

    /**
     * Вставка элемента в одномерный массив
     *
     * @param array<mixed> $array Исходный массив
     * @param integer $position Позиция для вставки
     * @param array<mixed> $insert_array Массив для вставки
     * @return array<mixed> Modified array
     */
    public static function insert(&$array, $position, $insert_array)
    {
        $first_array = array_splice($array, 0, $position);
        return array_merge($first_array, $insert_array, $array);
    }

    /**
     * Рекурсивное слияние массивов
     *
     * Функция принимает произвольное число аргументов-массивов для слияния
     * @param array<mixed> $array1 Массив, в который будут сливаться остальные
     * @param array<mixed> $array2 Массив для слияния
     * @return array<mixed> Recursively merged array
     */
    public static function mergeDeep(array $array1, array $array2 /* , ... */)
    {
        $arrays = func_get_args();
        $result = [];
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                // Recurse when both values are arrays.
                if (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
                    $result[$key] = self::mergeDeep($result[$key], $value);
                } elseif (is_integer($key) && isset($array[0])) {
                    // Renumber integer keys as array_merge_recursive() does. Note that PHP
                    // automatically converts array keys that are integer strings (e.g., '1')
                    // to integers.
                    $result[] = $value;
                } else { // Otherwise, use the later value, overriding any previous value.
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }

    /**
     * Глубокое слияние массивов
     *
     * @param array<mixed> $array1
     * @param array<mixed> $array2
     * @return array<mixed> Deeply concatenated array
     */
    public static function concatDeep(array $array1, array $array2 /* , ... */)
    {
        $arrays = func_get_args();
        $result = [];
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
                    $result[$key] = self::concatDeep($result[$key], $value);
                } else {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }

    public static function diff($array1, $array2)
    {
        $difference = [];
        foreach ($array1 as $key => $value) {
            if (is_array($value)) {
                if (!isset($array2[$key]) || !is_array($array2[$key])) {
                    $difference[$key] = $value;
                } else {
                    $newDiff = self::diff($value, $array2[$key]);
                    if (!empty($newDiff)) {
                        $difference[$key] = $newDiff;
                    }
                }
            } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                $difference[$key] = $value;
            }
        }
        return $difference;
    }

    /**
     *
     * @param stdClass $object
     * @return array<string, mixed> Converted object to associative array
     */
    public static function convertFromObject(stdClass $object)
    {
        $ret = [];
        $cloneObj = (array)(clone $object);
        foreach ($cloneObj as $k => $v) {
            $newValue = $v;
            if (is_object($newValue)) {
                $newValue = self::convertFromObject($newValue);
            } elseif (is_array($newValue)) {
                foreach ($newValue as $kk => $vv) {
                    if (is_object($vv)) {
                        $newValue[$kk] = self::convertFromObject($vv);
                    }
                }
            }
            $ret[$k] = $newValue;
        }
        return $ret;
    }

    public static function convertToObject(
        array $array,
        $checkNumericKeys = true,
        $forceToArray = false,
        array $hierarchy = [],
        $debug = false
    ): stdClass|array {
        // Возвращаем массив, если все ключи числовые, непрерывные, возрастающий от нуля
        $retIsArray = $forceToArray;
        if ($checkNumericKeys) {
            $retIsArray = !empty($array) ? true : $forceToArray;
            for ($i = 0; $i < count($array); $i++) {
                if (!isset($array[$i])) {
                    $retIsArray = false;
                    break;
                }
            }
        }

        $ret = $retIsArray ? [] : new stdClass();
        foreach ($array as $k => $v) {
            $newValue = $v;
            if (is_array($newValue)) {
                $nextForceToArray = false;
                $nextHierarchy = [];
                if (!empty($hierarchy[$k])) {
                    $nextHierarchy = $hierarchy[$k]['childs'] ?? [];
                    $nextForceToArray = !empty($hierarchy[$k]['type']) && ($hierarchy[$k]['type'] === 'hasMany');
                }
                $newValue = self::convertToObject($newValue, $checkNumericKeys, $nextForceToArray, $nextHierarchy);
            }
            if ($retIsArray) {
                $ret[$k] = $newValue;
            } else {
                $ret->$k = $newValue;
            }
        }
        return $ret; // (object) json_decode(json_encode($array));
    }

    /**
     * @return array<int, string> Keys present in source but not in dest
     */
    public static function diffKeys(array $source, array $dest): array
    {
        return array_diff(array_keys($source), array_keys($dest));
    }

    public static function stringCommasToArrayCheckBraces($str, $separator = ",", $leftbracket = "(", $rightbracket = ")", $quote = "'", $ignore_escaped_quotes = true)
    {
        $buffer = '';
        $stack = [];
        $depth = 0;
        $betweenquotes = false;
        $len = strlen($str);
        $char = "";
        for ($i = 0; $i < $len; $i++) {
            $previouschar = $char;
            $char = $str[$i];
            switch ($char) {
                case $separator:
                    if (!$betweenquotes) {
                        if (!$depth) {
                            if ($buffer !== '') {
                                $stack[] = $buffer;
                                $buffer = '';
                            }
                            continue 2;
                        }
                    }
                    break;
                case $quote:
                    if ($ignore_escaped_quotes) {
                        if ($previouschar != "\\") {
                            $betweenquotes = !$betweenquotes;
                        }
                    } else {
                        $betweenquotes = !$betweenquotes;
                    }
                    break;
                case $leftbracket:
                    if (!$betweenquotes) {
                        $depth++;
                    }
                    break;
                case $rightbracket:
                    if (!$betweenquotes) {
                        if ($depth) {
                            $depth--;
                        } else {
                            $stack[] = $buffer . $char;
                            $buffer = '';
                            continue 2;
                        }
                    }
                    break;
            }
            $buffer .= $char;
        }
        if ($buffer !== '') {
            $stack[] = $buffer;
        }
        return $stack;
    }


    /**
     * @return array<int, string> Array of trimmed non-empty string values
     */
    public static function stringCommasToArray(string $data, string $delimiter = ",")
    {
        $key = $delimiter . '/' . $data;
        if (!isset(self::$explodedValues[$key])) {
            self::$explodedValues[$key] = array_values(array_filter(array_map("trim", explode($delimiter, $data)), function ($item) {
                return !!$item;
            }));
        }
        return self::$explodedValues[$key];
    }

    /**
     * Прогулка по параметрам массива или объекта
     */
    public static function walkParams($value, $callback)
    {
        $isObject = is_object($value);
        if ($isObject || is_array($value)) {
            foreach ($value as $k => $v) {
                if ($isObject) {
                    $node = &$value->$k;
                } else {
                    $node = &$value[$k];
                }
                $node = self::walkParams($v, $callback);
            }
        } else {
            $value = $callback($value);
        }
        return $value;
    }

    /**
     * @return array<int, string> Array of UTF-8 string chunks
     */
    public static function strSplit($str, $length = 1)
    {
        $arr = [];
        $strLength = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $strLength; $i += $length) {
            $arr[] = mb_substr($str, $i, $length, 'UTF-8');
        }
        return $arr;
    }

    public static function keyBy($array, $keys, $replaceKeys = [])
    {
        if (!is_array($keys)) {
            $keys = self::stringCommasToArray($keys);
        }
        $ret = [];
        foreach ($array as $v) {
            $node = &$ret;
            foreach ($keys as $key) {
                $keyIsArray = false;
                if (substr($key, -2) === '[]') {
                    $keyIsArray = true;
                    $key = substr($key, 0, -2);
                }
                $index = $v->{$key} ?? (is_array($v) ? $v[$key] : 0);
                if (!empty($replaceKeys[$key][$index])) {
                    $index = $replaceKeys[$key][$index];
                }
                if (!isset($node[$index])) {
                    $node[$index] = [];
                }
                $node = &$node[$index];
                if ($keyIsArray) {
                    $newIndex = count($node);
                    $node[$newIndex] = [];
                    $node = &$node[$newIndex];
                }
            }
            $node = $v;
        }
        return $ret;
    }

    /**
     * @return array<mixed> Plucked values from array, optionally keyed by another field
     */
    public static function pluck($array, $value, $key = null)
    {
        $ret = [];
        foreach ($array as $v) {
            if ($key) {
                $ret[$v[$key]] = $v[$value];
            } else {
                $ret[] = $v[$value];
            }
        }
        return $ret;
    }

    /**
     * Получить значение из массива с учётом вероятностей
     *
     * @param array $probabilityArray [value => probability]
     * @param null|int $randValue Значение от 0 до 100 может быть задано
     *
     * @return int|null
     */
    public static function getKeyValueByProbability(array $probabilityArray, $randValue = null)
    {
        $ret = null;
        // Нормализуем массив вероятностей
        $sumProbability = array_sum(array_values($probabilityArray));
        if ($sumProbability) {
            foreach ($probabilityArray as $value => $probability) {
                $probabilityArray[$value] = round($probability / $sumProbability, 2) * 100;
            }
            arsort($probabilityArray);
            $c = 0;
            if (is_null($randValue)) {
                $randValue = mt_rand(0, 100);
            }
            foreach ($probabilityArray as $value => $probability) {
                $c += $probability;
                if ($randValue <= $c) {
                    $ret = $value;
                    break;
                }
            }
        }
        return $ret;
    }

    public static function getDescendantProp($arr, $dottedStr)
    {
        $dottedArr = explode(".", $dottedStr);
        while (count($dottedArr) > 0 && $arr) {
            $arr = (array) $arr;
            $v = array_shift($dottedArr);
            if (isset($arr[$v])) {
                $arr = $arr[$v];
            } else {
                $arr = null;
                break;
            }
        }
        return $arr;
    }

    public static function setDescendantProp(&$arr, $dottedStr, $value)
    {
        $dottedArr = explode(".", $dottedStr);
        $it = &$arr;
        while (count($dottedArr) > 0 && $it) {
            $it = &$it[array_shift($dottedArr)];
        }
        if ($it) {
            $it = $value;
        }
    }

    public static function replaceDescendantProps($text, $arr, ?Closure $cb = null)
    {
        return preg_replace_callback('/{.*?}/uis', function ($m) use ($arr, $cb) {
            $value = trim(mb_substr($m[0], 1, mb_strlen($m[0]) - 2));
            $ret = static::getDescendantProp($arr, $value);
            return $cb ? $cb($ret) : $ret;
        }, $text);
    }

    public static function fillAbsentValues(array $arr, $absentValue = null)
    {
        $headers = [];
        foreach ($arr as $arrValue) {
            foreach ($arrValue as $k => $v) {
                if (!in_array($k, $headers)) {
                    $headers[] = $k;
                }
            }
        }
        $ret = [];
        foreach ($arr as $k => $arrValue) {
            $value = [];
            foreach ($headers as $header) {
                $value[$header] = $arrValue[$header] ?? $absentValue;
            }
            $ret[$k] = $value;
        }
        return $ret;
    }

    public static function getFirstValue(array $arr)
    {
        return !empty($arr) ? array_values($arr)[0] : null;
    }

    public static function hasNumericKeys(array $arr)
    {
        $has = false;
        foreach ($arr as $k => $v) {
            if (is_numeric($k)) {
                $has = true;
                break;
            }
        }
        return $has;
    }

    public static function allNumericKeys(array $arr)
    {
        $all = true;
        foreach ($arr as $k => $v) {
            if (!is_numeric($k)) {
                $all = false;
                break;
            }
        }
        return $all;
    }

    public static function isArrayOfArrays(array $array)
    {
        $ret = false;
        if (isset($array[0])) {
            $ret = true;
        }
        if (!$ret) {
            foreach ($array as $k => $v) {
                $ret = is_array($v);
                break;
            }
        }
        return $ret;
    }

    public static function toArray($value)
    {
        if (is_object($value)) {
            $value = (array) $value;
        } elseif (is_scalar($value)) {
            $value = self::stringCommasToArray((string)$value);
        }
        return $value;
    }

    private static function cutFieldsOneDimensionalArray(array &$item, array $fields)
    {
        foreach ($item as $i => $v) {
            if (!in_array($i, $fields)) {
                unset($item[$i]);
            }
        }
    }

    public static function cutFields(array $array, array $fields)
    {
        if (self::isArrayOfArrays($array)) {
            foreach ($array as $k => $item) {
                self::cutFieldsOneDimensionalArray($array[$k], $fields);
            }
        } else {
            self::cutFieldsOneDimensionalArray($array, $fields);
        }
        return $array;
    }

    public static function isSubset(array $src, array $target)
    {
        foreach ($target as $k => $v) {
            if (!isset($src[$k]) || $src[$k] !== $v) {
                return false;
            }
        }
        return true;
    }

    public static function mergeDefaults(array $list, array $default)
    {
        foreach ($list as $k => $v) {
            $list[$k] = array_merge($default, $v);
        }
        return $list;
    }

    public static function shuffleAssoc(array &$array)
    {
        $keys = array_keys($array);
        shuffle($keys);
        $new = array();
        foreach ($keys as $key) {
            $new[$key] = $array[$key];
        }
        $array = $new;
    }

    public static function getRandom(array $arr, $limit = 1)
    {
        $count = count($arr);
        if ($count > $limit) {
            $arr = array_slice($arr, mt_rand(0, $count - $limit), $limit, true);
        }
        if ($arr) {
            self::shuffleAssoc($arr);
        }
        return $arr;
    }

    public static function getRandomOne(array $arr)
    {
        $ret = self::getRandom($arr);
        return $ret ? array_values($ret)[0] : null;
    }

    /**
     * @param array<int, string> $withStr
     * @return array<int, string> Extended array with name prefix
     */
    public static function extendsWithName(array $withStr, $name)
    {
        if ($name) {
            $withStr = array_map(function ($str) use ($name) {
                $delimiter = '.';
                if (in_array($str[0], ['(', '['])) {
                    $delimiter = '';
                } else {
                    $name = rtrim($name, '.');
                }
                return $name . $delimiter . $str;
            }, $withStr);
        }
        return $withStr;
    }
}
