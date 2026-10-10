<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Storage\StorageInterface;
use Jam\Models\Tests\Support\ZeroInsertIdStorage;

/**
 * Хранилище не сообщает insert id (возвращает 0).
 */
class ZeroIdAuthor extends Model
{
    protected $table = 'zero_authors';
    protected string $fields = 'id, name';
    protected $types = ['int' => 'id'];

    public function __construct($data = null)
    {
        $this->hasMany(AuthorNote::class, 'notes', 'id', 'author_id');
        parent::__construct($data);
    }

    protected function createStorage(): StorageInterface
    {
        return new ZeroInsertIdStorage();
    }
}
