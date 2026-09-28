<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

use Jam\Models\DummyModel;

/**
 * DummyModel: справочник, который живёт в коде, а не в таблице.
 * Поддерживает where (=, IN, AND/OR), orderBy, limit/offset и участие в связях; только чтение.
 */
class Role extends BaseModel implements DummyModel
{
    public const ADMIN = 1;
    public const EDITOR = 2;
    public const READER = 3;

    protected string $fields = 'id, code, title';
    protected $types = ['int' => 'id'];

    public function dummyRows(): array
    {
        return [
            ['id' => self::ADMIN, 'code' => 'admin', 'title' => 'Administrator'],
            ['id' => self::EDITOR, 'code' => 'editor', 'title' => 'Editor'],
            ['id' => self::READER, 'code' => 'reader', 'title' => 'Reader'],
        ];
    }
}
