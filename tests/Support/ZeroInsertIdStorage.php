<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Support;

use Jam\Models\Model;
use Jam\Models\Storage\Query;
use Jam\Models\Storage\StorageInterface;

/**
 * ArrayStorage, который, как MySQL при INSERT IGNORE без вставки или таблица без
 * автоинкремента, возвращает «пустой» insert id (0).
 */
final class ZeroInsertIdStorage implements StorageInterface
{
    private ArrayStorage $inner;

    public function __construct()
    {
        $this->inner = new ArrayStorage();
    }

    public function select(Model $model, Query $query): array
    {
        return $this->inner->select($model, $query);
    }

    public function aggregate(Model $model, Query $query, string $function, string $field): mixed
    {
        return $this->inner->aggregate($model, $query, $function, $field);
    }

    public function insert(Model $model, array $rows, bool $ignore = false, bool $upsert = false): int|string
    {
        $this->inner->insert($model, $rows, $ignore, $upsert);
        return 0;
    }

    public function update(Model $model, int|string|array $id, array $data): int
    {
        return $this->inner->update($model, $id, $data);
    }

    public function increment(Model $model, int|string $id, string $field, int|float $by): int
    {
        return $this->inner->increment($model, $id, $field, $by);
    }

    public function delete(Model $model, int|string|array $id): int
    {
        return $this->inner->delete($model, $id);
    }
}
