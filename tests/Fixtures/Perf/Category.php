<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class Category extends Model
{
    protected $table = 'perf_categories';
    protected string $fields = 'id, parent_id, name, slug, position';
    protected $types = ['int' => 'id, parent_id, position'];
}
