<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Support;

use Jam\Models\DummyQuery;
use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\Storage\Criteria;
use Jam\Models\Storage\Query;
use Jam\Models\Storage\StorageInterface;

/**
 * Не-SQL хранилище в памяти с поддержкой записи — модельный пример того, как подключить
 * документную БД (MongoDB и т.п.): всё, что умеет модель, выражается через этот интерфейс.
 *
 * Понимает только условия-массивы (Criteria); SQL-фрагменты, JOIN и GROUP BY отклоняет.
 */
final class ArrayStorage implements StorageInterface
{
    /** @var array<string, array<int|string, array<string, mixed>>> таблица => [id => строка] */
    public static array $tables = [];

    /** @var array<string, int> */
    private static array $autoIncrement = [];

    public static function reset(): void
    {
        self::$tables = [];
        self::$autoIncrement = [];
    }

    public function select(Model $model, Query $query): array
    {
        return $this->run($model, $query, $query->fields, $query->limit, $query->offset, $query->orderBy);
    }

    public function aggregate(Model $model, Query $query, string $function, string $field): mixed
    {
        $rows = $this->run($model, $query, null, null, null, null);
        $values = $field === '*' ? $rows : array_column($rows, $field);
        return match ($function) {
            'COUNT' => count($values),
            'SUM' => array_sum($values),
            'MIN' => $values ? min($values) : null,
            'MAX' => $values ? max($values) : null,
            'AVG' => $values ? array_sum($values) / count($values) : null,
        };
    }

    public function insert(Model $model, array $rows, bool $ignore = false, bool $upsert = false): int|string
    {
        $table = $model->table();
        $pk = $model->pk();
        $id = 0;
        foreach ($rows as $row) {
            $id = $row[$pk] ?? (self::$autoIncrement[$table] = (self::$autoIncrement[$table] ?? 0) + 1);
            if (isset(self::$tables[$table][$id])) {
                if ($ignore) {
                    continue;
                }
                if (!$upsert) {
                    throw new ModelException("Duplicate key $id in $table");
                }
            }
            self::$tables[$table][$id] = [$pk => $id] + $row + (self::$tables[$table][$id] ?? []);
        }
        return $id;
    }

    public function update(Model $model, int|string|array $id, array $data): int
    {
        $count = 0;
        foreach ((array) $id as $key) {
            if (isset(self::$tables[$model->table()][$key])) {
                self::$tables[$model->table()][$key] = $data + self::$tables[$model->table()][$key];
                $count++;
            }
        }
        return $count;
    }

    public function increment(Model $model, int|string $id, string $field, int|float $by): int
    {
        if (!isset(self::$tables[$model->table()][$id])) {
            return 0;
        }
        self::$tables[$model->table()][$id][$field] = (self::$tables[$model->table()][$id][$field] ?? 0) + $by;
        return 1;
    }

    public function delete(Model $model, int|string|array $id): int
    {
        $count = 0;
        foreach ((array) $id as $key) {
            if (isset(self::$tables[$model->table()][$key])) {
                unset(self::$tables[$model->table()][$key]);
                $count++;
            }
        }
        return $count;
    }

    private function run(Model $model, Query $query, ?array $fields, $limit, $offset, $orderBy): array
    {
        if ($query->hasRawSql()) {
            throw new ModelException(sprintf('%s supports only array conditions (Criteria), not SQL', self::class));
        }
        $conditions = $query->where;
        if ($query->softDeleteColumn !== null) {
            $conditions[] = new Criteria([$query->softDeleteColumn => null]);
        }
        return (new DummyQuery($model::class, $model->getFields()))->run(
            array_values(self::$tables[$query->table] ?? []),
            $conditions,
            $fields,
            $limit,
            $offset,
            $orderBy
        );
    }
}
