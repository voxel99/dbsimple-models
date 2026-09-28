<?php

namespace Jam\Models\Storage;

use Jam\Models\DummyModel;
use Jam\Models\DummyQuery;
use Jam\Models\Model;
use Jam\Models\ModelException;

/**
 * Хранилище только для чтения поверх DummyModel::dummyRows() — справочники, живущие в коде.
 *
 * Условия-массивы (Criteria) выполняются полностью, SQL-условия — в ограниченном виде
 * (=, IN, AND/OR), JOIN / GROUP BY не поддерживаются.
 */
class MemoryStorage implements StorageInterface
{
    public function select(Model $model, Query $query): array
    {
        return (new DummyQuery($model::class, $model->getFields()))->run(
            $this->rows($model),
            $this->conditions($model, $query),
            $query->fields,
            $query->limit,
            $query->offset,
            $query->orderBy
        );
    }

    public function aggregate(Model $model, Query $query, string $function, string $field): mixed
    {
        $rows = (new DummyQuery($model::class, $model->getFields()))
            ->run($this->rows($model), $this->conditions($model, $query), null, null, null, null);
        if ($function === 'COUNT') {
            return $field === '*' ? count($rows) : count(array_filter(array_column($rows, $field), fn($v) => $v !== null));
        }
        $values = array_column($rows, $field);
        if (!$values) {
            return null;
        }
        return match ($function) {
            'SUM' => array_sum($values),
            'AVG' => array_sum($values) / count($values),
            'MIN' => min($values),
            'MAX' => max($values),
            default => throw new ModelException("Unsupported aggregate function: $function"),
        };
    }

    public function insert(Model $model, array $rows, bool $ignore = false, bool $upsert = false): int|string
    {
        throw new ModelException('DummyModel is readonly');
    }

    public function update(Model $model, int|string|array $id, array $data): int
    {
        throw new ModelException('DummyModel is readonly');
    }

    public function increment(Model $model, int|string $id, string $field, int|float $by): int
    {
        throw new ModelException('DummyModel is readonly');
    }

    public function delete(Model $model, int|string|array $id): int
    {
        throw new ModelException('DummyModel is readonly');
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(Model $model): array
    {
        if (!$model instanceof DummyModel) {
            throw new ModelException(sprintf('%s requires %s', self::class, DummyModel::class));
        }
        return $model->dummyRows();
    }

    /** @return array<int, Criteria|string> */
    private function conditions(Model $model, Query $query): array
    {
        if ($query->joins || $query->groupBy) {
            throw new ModelException('DummyModel does not support JOIN / GROUP BY');
        }
        $conditions = array_map(
            fn($condition) => $condition instanceof Criteria ? $condition : $model->getDb()->subquery(...$condition)->get(),
            $query->where
        );
        if ($query->softDeleteColumn !== null) {
            $conditions[] = new Criteria([$query->softDeleteColumn => null]);
        }
        return $conditions;
    }
}
