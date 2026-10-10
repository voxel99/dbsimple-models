<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Storage\StorageInterface;
use Jam\Models\Tests\Support\ArrayStorage;

/**
 * description объявлено и в computed, и в extra: переданное значение имеет приоритет
 * над вычисляемым. title_upper — обычное вычислимое поле.
 */
class Document extends Model
{
    protected $table = 'documents';
    protected string $fields = 'id, title, body';
    protected string $computed = 'description, title_upper';
    protected string $extra = 'description';
    protected $types = ['int' => 'id'];

    protected function createStorage(): StorageInterface
    {
        return new ArrayStorage();
    }

    public function description(): string
    {
        return mb_substr((string) $this->body, 0, 10);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- matches field name via $computed
    public function title_upper(): string
    {
        return mb_strtoupper((string) $this->title);
    }
}
