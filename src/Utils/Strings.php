<?php

/**
 * Created by PhpStorm.
 * User: Voxel
 * Date: 23.03.2018
 * Time: 12:18
 */

namespace Jam\Models\Utils;

use InvalidArgumentException;

use function parse_url;

class Strings
{
    public static function swapPairs($str)
    {
        $newStr = "";
        $length = mb_strlen($str);
        for ($i = 0; $i < $length; $i += 2) {
            if (isset($str[$i]) && isset($str[$i + 1])) {
                $newStr[$i] = $str[$i + 1];
                $newStr[$i + 1] = $str[$i];
            } else {
                if (isset($str[$i])) {
                    $newStr[$i] = $str[$i];
                }
            }
        }
        return $newStr;
    }

    public static function ucfirst($string, $encoding = "UTF-8")
    {
        $strlen = mb_strlen($string, $encoding);
        $firstChar = mb_substr($string, 0, 1, $encoding);
        $then = mb_substr($string, 1, $strlen - 1, $encoding);
        return mb_strtoupper($firstChar, $encoding) . mb_strtolower($then);
    }

    public static function intervalValues($str)
    {
        $ret = [];
        $intervals = explode(',', trim((string) $str));

        foreach ($intervals as $interval) {
            $interval = trim($interval);
            if (preg_match('/^(\d+)-(\d+)$/', $interval, $matches)) {
                $min = (int) $matches[1];
                $max = (int) $matches[2];
                for ($i = $min; $i <= $max; $i++) {
                    $ret[] = $i;
                }
            } elseif (preg_match('/^\d+$/', $interval)) {
                $ret[] = (int) $interval;
            } else {
                throw new InvalidArgumentException(sprintf(
                    "Invalid numeric interval '%s'. Expected an ID or range such as 1-5",
                    $interval
                ));
            }
        }
        return $ret;
    }

    public static function pairValue($line, $notPairValue = '', $delim = '=')
    {
        $ret = [];
        if (strpos($line, $delim) === false) {
            $ret[trim($line)] = $notPairValue;
        } else {
            list($k, $v) = explode($delim, $line);
            $ret[trim($k)] = trim($v);
        }
        return $ret;
    }

    public static function pairValues($str, $notPairValue = '', $delimGroups = ",", $delimEq = "=")
    {
        $ret = [];
        if (!empty($str)) {
            $args = explode($delimGroups, $str);
            foreach ($args as $line) {
                $ret += self::pairValue($line, $notPairValue, $delimEq);
            }
        }
        return $ret;
    }

    public static function arrayToPairString(array $array, $delimiter = ',', $quotes = false)
    {
        $pairs = [];
        if ($quotes && is_bool($quotes)) {
            $quotes = '"';
        }
        foreach ($array as $k => $v) {
            if ($quotes) {
                $v = $quotes . str_replace($quotes, '\\' . $quotes, $v) . $quotes;
            }
            $pairs[] = $k . '=' . $v;
        }
        return implode($delimiter, $pairs);
    }

    public static function basedomain($host)
    {
        if (strpos($host, "//") !== false) {
            $host = parse_url($host, PHP_URL_HOST);
        }
        $parts = explode('.', $host);
        $f = array_slice($parts, -2, 1);
        $f = reset($f);
        $slice = (mb_strlen($f) == 2) && (count($parts) > 2) ? 3 : 2;
        return implode('.', array_slice($parts, (0 - $slice), $slice));
    }

    public static function myTrim($str, $size, $word = false)
    {
        if (mb_strlen($str) > $size) {
            if (!$word) {
                return mb_substr($str, 0, $size - 3) . '...';
            } else {
                $str = str_replace("\r", "", $str);
                $str_space = str_replace("\n", " ", $str);
                $words = explode(' ', mb_substr($str_space, 0, $size - 3));
                $len = mb_strlen(implode(' ', count($words) > 1 ? array_slice($words, 0, count($words) - 1) : $words));
                $str = mb_substr($str, 0, $len) . '...';
            }
        }
        return $str;
    }

    public static function castTo($type, $value)
    {
        $ret = $value;
        switch ($type) {
            case "int":
                $ret = (int)$value;
                break;
            case "uint":
                $ret = sprintf("%u", $value);
                break;
        }
        return $ret;
    }

    public static function replaceFromBraces($text, $from, $to, $openingBraces = null, $closingBraces = null, $inside = false)
    {
        // Заменяем запятые вне скобок на разделитель в виде вертикальной линии
        $length = mb_strlen($text);
        $stack = [];
        $newText = "";
        if (is_null($openingBraces)) {
            $openingBraces = ["(", "["];
        } else {
            $openingBraces = (array)$openingBraces;
        }
        if (is_null($closingBraces)) {
            $closingBraces = [")", "]"];
        } else {
            $closingBraces = (array)$closingBraces;
        }

        $from = (array)$from;
        $to = (array)$to;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if (in_array($char, $openingBraces)) {
                array_push($stack, $char);
            } else {
                if (in_array($char, $closingBraces)) {
                    array_pop($stack);
                } else {
                    $index = array_search($char, $from);
                    $cond = $inside ? !empty($stack) : empty($stack);
                    if ($cond && ($index !== false)) {
                        $char = $to[$index];
                    }
                }
            }
            $newText .= $char;
        }
        return $newText;
    }

    public static function replaceInBraces($text, $from, $to, $openingBraces = null, $closingBraces = null)
    {
        return self::replaceFromBraces($text, $from, $to, $openingBraces, $closingBraces, true);
    }

    public static function replaceOutBraces($text, $from, $to, $openingBraces = null, $closingBraces = null)
    {
        return self::replaceFromBraces($text, $from, $to, $openingBraces, $closingBraces, false);
    }

    public static function reSplitFromBraces($text, $from, $openingBraces = null, $closingBraces = null, $inside = false)
    {
        $delimiter = chr(128);
        $str = self::replaceFromBraces($text, $from, $delimiter, $openingBraces, $closingBraces, $inside);
        return ArrayHelper::stringCommasToArray($str, $delimiter);
    }

    public static function reSplitInBraces($text, $from, $openingBraces = null, $closingBraces = null)
    {
        return self::reSplitFromBraces($text, $from, $openingBraces, $closingBraces, true);
    }

    public static function reSplitOutBraces($text, $from, $openingBraces = null, $closingBraces = null)
    {
        return self::reSplitFromBraces($text, $from, $openingBraces, $closingBraces, false);
    }

    /**
     * Вывод окончаний русских слов с учетом числительных (например сообщение сообщения сообщений)
     *
     * @param integer $n число
     * @param string $form1 единственное
     * @param string $form2 форма для 2-4
     * @param string $form5 форма для 5 и более
     * @return string нужная форма
     */
    public static function pluralForm($n, $form1, $form2, $form5)
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $form5;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $form2;
        }
        if ($n1 == 1) {
            return $form1;
        }
        return $form5;
    }

    //////////////////////////////////////////////////////////////////////
    // PARA: Date Should In YYYY-MM-DD Format
    // RESULT FORMAT:
    // '%y Year %m Month %d Day %h Hours %i Minute %s Seconds'      =>  1 Year 3 Month 14 Day 11 Hours 49 Minute 36 Seconds
    // '%y Year %m Month %d Day'                                    =>  1 Year 3 Month 14 Days
    // '%m Month %d Day'                                            =>  3 Month 14 Day
    // '%d Day %h Hours'                                            =>  14 Day 11 Hours
    // '%d Day'                                                     =>  14 Days
    // '%h Hours %i Minute %s Seconds'                              =>  11 Hours 49 Minute 36 Seconds
    // '%i Minute %s Seconds'                                       =>  49 Minute 36 Seconds
    // '%h Hours                                                    =>  11 Hours
    // '%a Days                                                     =>  468 Days
    //////////////////////////////////////////////////////////////////////
    public static function dateDifference($date_1, $date_2, $differenceFormat = '%a')
    {
        $datetime1 = date_create($date_1);
        $datetime2 = date_create($date_2);

        $interval = date_diff($datetime1, $datetime2);

        return $interval->format($differenceFormat);
    }

    public static function validatePhone($phone)
    {
        $filteredPhoneNumber = self::stripPhone($phone);
        // Check the lenght of number
        // This can be customized if you want phone number from a specific country
        $s = strlen($filteredPhoneNumber);
        return $s >= 10 && $s <= 14;
    }

    public static function stripPhone($phone)
    {
        // Allow +, - and . in phone number
        $filteredPhoneNumber = filter_var($phone, FILTER_SANITIZE_NUMBER_INT);
        // Remove "-" from number
        return str_replace("-", "", $filteredPhoneNumber);
    }

    public static function durationToSeconds($time)
    {
        $seconds = 0;
        if ($time) {
            $timeArr = array_reverse(explode(":", $time));
            foreach ($timeArr as $key => $value) {
                if ($key > 2) {
                    break;
                }
                $seconds += (60 ** $key) * (int) $value;
            }
        }
        return $seconds;
    }

    public static function getFileExtension($name)
    {
        $n = strrpos($name, '.');
        return ($n === false) ? '' : substr($name, $n + 1);
    }

    public static function br2nl($text, $nl = "\n")
    {
        $text = preg_replace("/(\r\n|\n|\r)/", "", $text);
        return preg_replace("#<br\s*/?>#i", $nl, $text);
    }
}
