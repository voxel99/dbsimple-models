<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Support;

/**
 * Копия глобальной функции translit() приложения, которой SlugEvaluatable пользовался до
 * появления Strings::translit(): эталон для сверки слагов с уже сохранёнными в БД.
 */
final class LegacyTranslit
{
    public static int $calls = 0;

    public static function translit($str, $dummySymbol = '', $exceptSymbols = '', $protectedSymbols = [])
    {
        $replace = array(
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'ж' => 'zh',
            'з' => 'z', 'и' => 'i', 'й' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
            'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'eh', 'ю' => 'yu',
            'я' => 'ya',
            '"' => '', '\'' => '', '.' => '',
            'ñ' => 'n', 'ç' => 'c', '¿' => '', '¡' => '',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u'
        );

        if ($exceptSymbols) {
            for ($i = 0; $i < mb_strlen($exceptSymbols); $i++) {
                unset($replace[$exceptSymbols[$i]]);
            }
        }
        $str = str_replace(array_keys($replace), array_values($replace), mb_strtolower(trim((string) $str)));
        return trim(preg_replace("#[^0-9a-z" . $exceptSymbols . "]#ui", $dummySymbol, $str));
    }
}
