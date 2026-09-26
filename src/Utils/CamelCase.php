<?php

namespace Jam\Models\Utils;

class CamelCase
{
    /**
     * Конвертирует строку с подчёркиваниями в имя в венгерской нотации
     *
     * @param string $input Исходная строка
     * @return string
     */
    public static function to($input, $delimiter = "_")
    {
        $arr = explode($delimiter, $input);
        $ret = '';
        foreach ($arr as $value) {
            $ret .= ucfirst($value);
        }
        return $ret;
    }

    /**
     * Конвертирует строку в венгерской нотации в имя с подчёркиваниями
     *
     * @param string $input Исходная строка
     * @return string
     */
    public static function from($input, $delimiter = "_")
    {
        preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
        $ret = $matches[0];
        foreach ($ret as &$match) {
            $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
        }
        return implode($delimiter, $ret);
    }
}
