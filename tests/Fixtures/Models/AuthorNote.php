<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Storage\StorageInterface;
use Jam\Models\Tests\Support\ArrayStorage;

class AuthorNote extends Model
{
    protected $table = 'author_notes';
    protected string $fields = 'id, author_id, text';
    protected $types = ['int' => 'id, author_id'];

    public function __construct($data = null)
    {
        $this->belongs(ZeroIdAuthor::class, 'author', 'author_id', 'id');
        parent::__construct($data);
    }

    protected function createStorage(): StorageInterface
    {
        return new ArrayStorage();
    }
}
