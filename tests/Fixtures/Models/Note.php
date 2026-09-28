<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Storage\StorageInterface;
use Jam\Models\Tests\Support\ArrayStorage;
use Jam\Models\Traits\Timestamps;

/**
 * Модель на не-SQL хранилище со связью на SQL-модель User.
 */
class Note extends Model
{
    use Timestamps;

    protected $table = 'notes';
    protected string $fields = 'id, user_id, text, tags, pinned, created_at, updated_at';
    protected string $computed = 'preview';
    protected $types = [
        'int' => 'id, user_id',
        'bool' => 'pinned',
        'json' => 'tags',
    ];

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'author', 'user_id', 'id');
        parent::__construct($data);
    }

    protected function createStorage(): StorageInterface
    {
        return new ArrayStorage();
    }

    public function preview(): string
    {
        return mb_substr((string) $this->text, 0, 10);
    }
}
