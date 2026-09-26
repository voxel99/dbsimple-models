<?php

namespace Jam\Models\Cast;

class CastJson extends CastClosure
{
    /**
     * Карта типов под-полей 1 уровня: имя под-ключа => встроенный тип (см. JsonFieldTypes).
     * Заполняется из объявления $types вида `options(... flags: flags ...)`.
     *
     * @var array<string, string>
     */
    private array $topLevelKeyTypes = [];

    /**
     * @param array<string, string> $types
     */
    public function setTopLevelKeyTypes(array $types): void
    {
        $this->topLevelKeyTypes = $types;
    }

    public function dbValue($value)
    {
        // При записи в БД приводим типизированные под-поля к их db-представлению
        // (например карта флагов 'yes'/'no' → 1/0), затем кодируем в JSON.
        if ($this->topLevelKeyTypes && (is_object($value) || is_array($value))) {
            $value = JsonFieldTypes::apply($this->topLevelKeyTypes, $value, JsonFieldTypes::DIRECTION_DB);
        }
        return json_encode($value);
    }

    public function externalValue($value)
    {
        // Внешнее (PHP) представление оставляем числовым для карт флагов, чтобы проверки
        // в коде не ломались на строке 'no'. Строковое 'yes'/'no' выдаётся только в
        // stringValue (путь toArray → API).
        return $value ? json_decode($value) : null;
    }

    public function stringValue($value)
    {
        $decoded = $this->checkValue($value) === self::CAST_DB
            ? $this->externalValue($value)
            : $value;

        if ($this->topLevelKeyTypes && (is_object($decoded) || is_array($decoded))) {
            $decoded = JsonFieldTypes::apply($this->topLevelKeyTypes, $decoded, JsonFieldTypes::DIRECTION_STRING);
        }

        return $decoded;
    }

    public function checkValue($value)
    {
        return is_string($value)
            ? CastClosure::CAST_DB
            : CastClosure::CAST_EXTERNAL;
    }
}
