<?php

namespace Jam\Models\Cast;

use Jam\Models\Model;

/**
 * Реестр встроенных типов под-полей JSON-колонок.
 *
 * Модель объявляет тип под-ключа JSON-поля через двоеточие в $types, например:
 *   "json" => "options(cols, rows, flags: flags, flags_poll: flags, extra_*)"
 *
 * Здесь под-ключи `flags` и `flags_poll` получают встроенный тип `flags`: их значения
 * двусторонне преобразуются — наружу (toArray/API) числа 1/0 → 'yes'/'no', а при записи
 * в БД 'yes'/'no' → 1/0. В PHP-чтении (externalValue) представление остаётся числовым,
 * как у CastFlags, чтобы !empty/проверки в коде не ломались на строке 'no'.
 */
class JsonFieldTypes
{
    public const TYPE_FLAGS = 'flags';

    /** Преобразование к внешнему строковому представлению (toArray/API). */
    public const DIRECTION_STRING = 'string';

    /** Преобразование к представлению для БД (запись). */
    public const DIRECTION_DB = 'db';

    /**
     * Известен ли встроенный тип под-поля.
     */
    public static function isKnown(string $type): bool
    {
        return $type === self::TYPE_FLAGS;
    }

    /**
     * Применяет объявленные типы под-полей к payload JSON-поля.
     *
     * @param array<string, string> $typesMap карта под-ключ => тип (например ['flags' => 'flags'])
     * @param object|array|mixed     $payload  декодированный объект/массив JSON-поля
     * @param string                 $direction self::DIRECTION_STRING | self::DIRECTION_DB
     * @return object|array|mixed payload с преобразованными под-полями (того же контейнерного типа)
     */
    public static function apply(array $typesMap, $payload, string $direction)
    {
        if (empty($typesMap) || (!is_object($payload) && !is_array($payload))) {
            return $payload;
        }

        $isObject = is_object($payload);
        // Не мутируем исходный контейнер: для объекта берём поверхностную копию (массивы
        // в PHP копируются по значению). convertFlagMap создаёт новые вложенные карты,
        // поэтому поверхностной копии достаточно — исходный объект модели не меняется.
        if ($isObject) {
            $payload = clone $payload;
        }
        foreach ($typesMap as $key => $type) {
            $hasKey = $isObject ? isset($payload->{$key}) : array_key_exists($key, $payload);
            if (!$hasKey) {
                continue;
            }

            $value = $isObject ? $payload->{$key} : $payload[$key];
            $converted = self::convert($type, $value, $direction);

            if ($isObject) {
                $payload->{$key} = $converted;
            } else {
                $payload[$key] = $converted;
            }
        }

        return $payload;
    }

    /**
     * Прямое преобразование одной карты флагов в обе стороны (без обёртки payload).
     * Удобно там, где значение под-поля обрабатывается отдельно (например при сохранении).
     *
     * @param object|array|mixed $map
     * @return object|array|mixed
     */
    public static function flagMap($map, string $direction)
    {
        return self::convertFlagMap($map, $direction);
    }

    /**
     * Преобразует значение одного под-поля согласно его типу.
     *
     * @param object|array|mixed $value
     * @return object|array|mixed
     */
    private static function convert(string $type, $value, string $direction)
    {
        if ($type === self::TYPE_FLAGS) {
            return self::convertFlagMap($value, $direction);
        }

        return $value;
    }

    /**
     * Преобразует карту флагов {ключ: значение} в обе стороны.
     *
     * @param object|array|mixed $map
     * @return object|array|mixed карта того же контейнерного типа
     */
    private static function convertFlagMap($map, string $direction)
    {
        if (!is_object($map) && !is_array($map)) {
            return $map;
        }

        $isObject = is_object($map);
        $result = $isObject ? new \stdClass() : [];
        foreach ($map as $name => $flagValue) {
            $on = self::isOn($flagValue);
            $converted = $direction === self::DIRECTION_STRING
                ? ($on ? Model::VALUE_YES : Model::VALUE_NO)
                : ($on ? 1 : 0);

            if ($isObject) {
                $result->{$name} = $converted;
            } else {
                $result[$name] = $converted;
            }
        }

        return $result;
    }

    /**
     * Истинность значения флага:
     * значение «включено», если оно не пустое и не одно из 'false'/'no'/'0'.
     *
     * @param mixed $value
     */
    private static function isOn($value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', 'false', 'no', '0'], true);
        }

        return !empty($value);
    }
}
