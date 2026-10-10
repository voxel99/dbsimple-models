<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

/** Модель для отношений, которые объявлены, но в выборке не загружаются */
class Counter extends Model
{
    protected $table = 'perf_counters';
    protected string $fields = 'id, user_id, post_id, kind, value';
    protected $types = ['int' => 'id, user_id, post_id, value'];
}
