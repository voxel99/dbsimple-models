<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\DummyModel;
use Jam\Models\Model;

/**
 * Справочник, живущий в коде, а не в БД: поддерживает where/order/limit и связи, но только для чтения.
 */
class Role extends Model implements DummyModel
{
    protected string $fields = 'id, code, title';
    protected $types = ['int' => 'id'];

    public function dummyRows(): array
    {
        return [
            ['id' => 1, 'code' => 'admin', 'title' => 'Administrator'],
            ['id' => 2, 'code' => 'editor', 'title' => 'Editor'],
            ['id' => 3, 'code' => 'viewer', 'title' => 'Viewer'],
        ];
    }
}
