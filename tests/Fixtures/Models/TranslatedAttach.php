<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Storage\StorageInterface;
use Jam\Models\Tests\Support\ArrayStorage;

/**
 * Как в приложении (PostAttach и т.п.): description — computed (читает переводы) и extra
 * (переданное значение важнее вычисляемого).
 */
class TranslatedAttach extends Model
{
    protected $table = 'translated_attaches';
    protected string $fields = 'id, post_id';
    protected string $computed = 'description';
    protected string $extra = 'description';
    protected $types = ['int' => 'id, post_id'];

    public function __construct($data = null)
    {
        $this->belongs(Tag::class, 'tag', 'post_id', 'id');
        parent::__construct($data);
    }

    protected function createStorage(): StorageInterface
    {
        return new ArrayStorage();
    }

    public function description(): string
    {
        return '';
    }
}
