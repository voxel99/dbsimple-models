<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;

class Tag extends Model
{
    protected $table = 'tags';
    protected string $fields = 'id, name';
    protected $types = ['int' => 'id'];
}
