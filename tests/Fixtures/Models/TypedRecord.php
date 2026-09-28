<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Tests\Fixtures\Casts\MoneyCast;

/** Модель-«витрина» всех встроенных приведений типов. */
class TypedRecord extends Model
{
    protected string $fields = 'id, qty, big, ratio, day, moment, ip, options, parent_id, price, code, tags, bits, active';
    protected $types = [
        'int' => 'id, qty',
        'uint' => 'big',
        'float' => 'ratio',
        'date' => 'day',
        'datetime' => 'moment',
        'ip' => 'ip',
        // top-level ключи JSON доступны как свойства модели; notify — карта флагов, x_* — маска
        'json' => 'options(theme, lang, notify: flags, x_*)',
        'null' => 'parent_id',
        'closure' => ['price' => MoneyCast::class],
        'string' => 'code',
        'delim' => ['tags' => '|'],
        'flags' => ['bits' => ['read' => 0, 'write' => 1, 'level' => '2-3']],
        'bool' => 'active',
    ];
}
