<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Casts;

use Jam\Models\Cast\CastClosure;

/**
 * Пользовательский cast: в БД хранятся копейки (int), снаружи — рубли (float).
 */
class MoneyCast extends CastClosure
{
    public function dbValue($value)
    {
        return (int) round(((float) $value) * 100);
    }

    public function externalValue($value)
    {
        return ((int) $value) / 100;
    }

    /** Определяем слой по самому значению: int/цифровая строка — это БД, float — внешний. */
    public function checkValue($value)
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? self::CAST_DB
            : self::CAST_EXTERNAL;
    }
}
