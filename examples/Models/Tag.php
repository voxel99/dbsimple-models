<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

class Tag extends BaseModel
{
    protected $table = 'tags';
    protected string $fields = 'id, name';
    protected $types = ['int' => 'id'];
}
