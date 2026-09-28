<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;

/** Модель со строковым первичным ключом (key-value настройки). */
class Option extends Model
{
    protected $table = 'options';
    protected $pk = 'name';
    protected string $fields = 'name, value';
    protected $types = ['json' => 'value'];
}
