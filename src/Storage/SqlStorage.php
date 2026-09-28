<?php

namespace Jam\Models\Storage;

use Jam\DbSimple\SubQuery;
use Jam\Models\Model;

/**
 * Хранилище на DbSimple: MySQL / MariaDB, PostgreSQL, SQLite.
 *
 * Генерирует SQL в диалекте MySQL (обратные кавычки, INSERT IGNORE, ON DUPLICATE KEY UPDATE);
 * адаптеры SQLite/PostgreSQL DbSimple сами переводят то, что умеют.
 */
class SqlStorage implements StorageInterface
{
    public function select(Model $model, Query $query): array
    {
        return $this->runSelect($model, $query, implode(', ', $this->selectedFields($query)));
    }

    public function aggregate(Model $model, Query $query, string $function, string $field): mixed
    {
        $expression = sprintf('%s(%s) AS aggregate', $function, $this->escapeField($field));
        $rows = $this->runSelect($model, $query, $expression);
        return $rows[0]['aggregate'] ?? null;
    }

    public function insert(Model $model, array $rows, bool $ignore = false, bool $upsert = false): int|string
    {
        $db = $model->getDb();
        $isMulti = count($rows) > 1;
        $fields = array_keys($rows[0]);
        $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '')
            . 'INTO ?_' . $model->table()
            . ' (?#) VALUES (?a)'
            . ($upsert ? $this->onDuplicateKeyUpdateExpression($model, $fields, $rows[0]) : '');

        if ($upsert) {
            $db->query('SET SESSION sql_mode = "NO_ENGINE_SUBSTITUTION"');
        }
        try {
            $id = $db->query($sql, $fields, $isMulti ? array_values($rows) : array_values($rows[0]));
        } finally {
            if ($upsert) {
                $db->query('SET SESSION sql_mode = @@GLOBAL.sql_mode');
            }
        }
        return $id ?: 0;
    }

    public function update(Model $model, int|string|array $id, array $data): int
    {
        // `?`, а не `?d`: первичный ключ может быть строковым
        return (int) $model->getDb()->query(
            'UPDATE ?_' . $model->table() . ' SET ?a WHERE ?# ' . (is_array($id) ? 'IN (?a)' : '= ?'),
            $data,
            $model->pk(),
            $id
        );
    }

    public function increment(Model $model, int|string $id, string $field, int|float $by): int
    {
        return (int) $model->getDb()->query(
            'UPDATE ?_' . $model->table() . ' SET ?# = ?# + ' . (is_float($by) ? '?f' : '?d') . ' WHERE ?# = ?',
            $field,
            $field,
            $by,
            $model->pk(),
            $id
        );
    }

    public function delete(Model $model, int|string|array $id): int
    {
        return (int) $model->getDb()->query(
            'DELETE FROM ?_' . $model->table() . ' WHERE ?# ' . (is_array($id) ? 'IN (?a)' : '= ?'),
            $model->pk(),
            $id
        );
    }

    /**
     * WHERE-выражение (без слова WHERE): условия через AND + фильтр SoftDeletes.
     */
    public function whereSql(Model $model, Query $query): string
    {
        $conditions = array_map(fn($condition) => $this->conditionSql($model, $condition), $query->where);
        $where = count($conditions) > 1
            ? '(' . implode(') AND (', array_unique($conditions)) . ')'
            : ($conditions[0] ?? '');

        if ($query->softDeleteColumn !== null) {
            $where .= ($where ? ' AND' : '')
                . sprintf(' %s%s IS NULL', $query->alias ? $query->alias . '.' : '', $query->softDeleteColumn);
        }
        return $where;
    }

    /**
     * @param Criteria|array<int, mixed> $condition
     */
    public function conditionSql(Model $model, Criteria|array $condition): string
    {
        $db = $model->getDb();
        if ($condition instanceof Criteria) {
            return $condition->toSql(
                static fn($value) => $value === null ? 'NULL' : (is_bool($value) ? (int) $value : $db->escape($value)),
                static fn(string $identifier) => $db->escape($identifier, true)
            );
        }
        return $db->subquery(...$condition)->get();
    }

    private function runSelect(Model $model, Query $query, string $selectExpression): array
    {
        $where = $this->whereSql($model, $query);
        $joins = $this->joinsSql($model, $query);
        [$orderBy, $direction] = $this->orderByParts($query->orderBy);
        $groupBy = $query->groupBy ? $this->normalizeOperand($query->groupBy) : '';

        $rows = $model->getDb()->select(
            'SELECT ' . $selectExpression
            . ' FROM ?_' . $query->table . ($query->alias ? ' AS ' . $query->alias : '')
            . ($joins ? ' ' . $joins : '')
            . ($where ? ' WHERE ' . $where : '')
            . ($groupBy ? ' GROUP BY ' . $groupBy : '')
            . ($orderBy ? ' ORDER BY ' . $orderBy . ' ' . $direction : '')
            . '{ LIMIT ?d}{ OFFSET ?d}' . $query->lock,
            // OFFSET без LIMIT — синтаксическая ошибка в MySQL и SQLite
            $query->limit ?: ($query->offset ? PHP_INT_MAX : DBSIMPLE_SKIP),
            $query->offset ?: DBSIMPLE_SKIP
        );
        return $rows ?: [];
    }

    /** @return array<int, string> */
    private function selectedFields(Query $query): array
    {
        if (!$query->fields) {
            return ['*'];
        }
        return array_map(
            fn(string $field) => str_contains($field, '*') ? $field : $this->escapeField($field),
            $query->fields
        );
    }

    private function escapeField(string $field): string
    {
        foreach ([' ', '.', '`', '*', '('] as $special) {
            if (str_contains($field, $special)) {
                return $field;
            }
        }
        return '`' . $field . '`';
    }

    private function joinsSql(Model $model, Query $query): string
    {
        $sql = [];
        foreach ($query->joins as $type => $joins) {
            foreach ($joins as $join) {
                if (!$join) {
                    continue;
                }
                $expression = $join instanceof SubQuery ? $join->get() : $model->getDb()->subquery(...$join)->get();
                if ($expression) {
                    $sql[] = $type . ' ' . preg_replace('#^' . $type . '#i', '', $expression);
                }
            }
        }
        return implode("\n", $sql);
    }

    /**
     * @return array{0: string|null, 1: string} [выражение сортировки, направление]
     */
    private function orderByParts(mixed $orderBy): array
    {
        if (!$orderBy) {
            return [null, ''];
        }
        if (is_object($orderBy) && !empty($orderBy->key)) {
            return [$this->normalizeOperand($orderBy->key), $orderBy->order ?? ''];
        }
        // Составная сортировка «num, id» / «updated_at DESC, id DESC»
        if (str_contains($orderBy, ',') && !str_contains($orderBy, '(')) {
            return [$this->multiColumnOrderBy($orderBy), ''];
        }
        [$column, $direction] = array_pad(explode(' ', $orderBy, 2), 2, '');
        return [$this->normalizeOperand($column), $direction];
    }

    private function multiColumnOrderBy(string $orderBy): string
    {
        $parts = [];
        foreach (explode(',', $orderBy) as $part) {
            $tokens = preg_split('/\s+/', trim($part)) ?: [];
            if (!$tokens || $tokens[0] === '') {
                continue;
            }
            $direction = strtoupper($tokens[1] ?? '');
            $parts[] = Model::dbEsc($tokens[0]) . (in_array($direction, ['ASC', 'DESC'], true) ? ' ' . $direction : '');
        }
        return implode(', ', $parts);
    }

    private function normalizeOperand(string $operand): string
    {
        return str_contains($operand, '(') ? $operand : Model::dbEsc($operand);
    }

    /**
     * @param array<int, string> $fields
     * @param array<string, mixed> $firstRow
     */
    private function onDuplicateKeyUpdateExpression(Model $model, array $fields, array $firstRow): string
    {
        $parts = array_map(static fn($f) => '`' . $f . '` = VALUES(`' . $f . '`)', $fields);

        // Если строка обновилась, LAST_INSERT_ID() не информативен; LAST_INSERT_ID(pk) заставляет
        // его вернуть id существующей строки. Только для числовых ключей: для строкового ключа
        // LAST_INSERT_ID('abc') = 0, и ключ строки перезаписывался бы нулём.
        $pk = $model->pk();
        $pkValue = $firstRow[$pk] ?? null;
        if ($pkValue === null || is_numeric($pkValue)) {
            $parts[] = '`' . $pk . '` = LAST_INSERT_ID(`' . $pk . '`)';
        }

        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $parts);
    }
}
