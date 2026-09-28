<?php

namespace Jam\Models\Storage;

use InvalidArgumentException;

/**
 * Условие выборки в виде массива — не зависит от SQL.
 *
 * <code>
 * $model->where([
 *     'user_id' => 5,                        // = 5
 *     'status'  => [1, 2],                   // IN (1, 2)
 *     'deleted_at' => null,                  // IS NULL
 *     'views'   => ['>=' => 10, '<' => 100], // несколько операторов по одному полю (AND)
 *     'title'   => ['like' => 'PHP%'],
 *     'p.category_id' => ['!=' => 3],        // поле с алиасом таблицы
 *     Criteria::OR => [                      // (a) OR (b)
 *         ['is_pinned' => 1],
 *         ['created_at' => ['>' => '2024-01-01']],
 *     ],
 * ]);
 * </code>
 *
 * Операторы: =, !=, <>, >, >=, <, <=, in, not in, like, not like.
 * Хранилище (SqlStorage, MemoryStorage, ...) само решает, как выполнить условие:
 * toSql() для SQL, matches() для проверки строки в памяти.
 */
final class Criteria
{
    public const OR = '$or';
    public const AND = '$and';

    private const OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<=', 'in', 'not in', 'like', 'not like'];

    /**
     * @param array<string, mixed> $conditions
     */
    public function __construct(private array $conditions)
    {
        foreach ($conditions as $field => $value) {
            if ($field === self::OR || $field === self::AND) {
                if (!is_array($value)) {
                    throw new InvalidArgumentException("Criteria $field expects a list of condition arrays");
                }
                continue;
            }
            if (!is_string($field) || $field === '') {
                throw new InvalidArgumentException('Criteria keys must be field names, got: ' . var_export($field, true));
            }
            if (is_array($value) && !array_is_list($value)) {
                foreach (array_keys($value) as $op) {
                    if (!in_array(strtolower((string) $op), self::OPERATORS, true)) {
                        throw new InvalidArgumentException("Unknown operator [$op] for field [$field]");
                    }
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->conditions;
    }

    /** Все поля, упомянутые в условии (включая вложенные группы) */
    public function fields(): array
    {
        $fields = [];
        foreach ($this->conditions as $field => $value) {
            if ($field === self::OR || $field === self::AND) {
                foreach ($value as $group) {
                    $fields = array_merge($fields, (new self($group))->fields());
                }
                continue;
            }
            $fields[] = $field;
        }
        return array_values(array_unique($fields));
    }

    public function mentions(string $field): bool
    {
        foreach ($this->fields() as $f) {
            if ($f === $field || str_ends_with($f, '.' . $field)) {
                return true;
            }
        }
        return false;
    }

    /**
     * SQL-условие.
     *
     * @param callable(mixed): string $escapeValue      экранирование значения
     * @param callable(string): string $escapeIdentifier экранирование идентификатора
     */
    public function toSql(callable $escapeValue, callable $escapeIdentifier): string
    {
        $parts = [];
        foreach ($this->conditions as $field => $value) {
            if ($field === self::OR || $field === self::AND) {
                $groups = array_map(
                    fn(array $group) => '(' . (new self($group))->toSql($escapeValue, $escapeIdentifier) . ')',
                    $value
                );
                if ($groups) {
                    $parts[] = count($groups) > 1 ? '(' . implode($field === self::OR ? ' OR ' : ' AND ', $groups) . ')' : $groups[0];
                } elseif ($field === self::OR) {
                    $parts[] = '1 = 0'; // пустое OR — ложь
                }
                continue;
            }
            $column = implode('.', array_map($escapeIdentifier, explode('.', $field)));
            foreach ($this->normalize($value) as [$op, $operand]) {
                $parts[] = $this->comparisonSql($column, $op, $operand, $escapeValue);
            }
        }
        return $parts ? implode(' AND ', $parts) : '1 = 1';
    }

    /**
     * Проверка строки в памяти (DummyModel, будущие не-SQL хранилища).
     *
     * @param array<string, mixed> $row
     */
    public function matches(array $row): bool
    {
        foreach ($this->conditions as $field => $value) {
            if ($field === self::OR || $field === self::AND) {
                $results = array_map(fn(array $group) => (new self($group))->matches($row), $value);
                $ok = $field === self::OR ? in_array(true, $results, true) : !in_array(false, $results, true);
                if (!$ok) {
                    return false;
                }
                continue;
            }
            // Поле с алиасом (p.user_id) ищем по короткому имени
            $key = $field;
            if (!array_key_exists($key, $row) && str_contains($field, '.')) {
                $key = substr($field, strrpos($field, '.') + 1);
            }
            $actual = $row[$key] ?? null;
            foreach ($this->normalize($value) as [$op, $operand]) {
                if (!$this->compare($actual, $op, $operand)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @return array<int, array{0: string, 1: mixed}> Пары [оператор, операнд]
     */
    private function normalize(mixed $value): array
    {
        if ($value === null) {
            return [['=', null]];
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return [['in', $value]];
            }
            $pairs = [];
            foreach ($value as $op => $operand) {
                $pairs[] = [strtolower((string) $op), $operand];
            }
            return $pairs;
        }
        return [['=', $value]];
    }

    private function comparisonSql(string $column, string $op, mixed $operand, callable $escapeValue): string
    {
        if ($operand === null) {
            return match ($op) {
                '=' => "$column IS NULL",
                '!=', '<>' => "$column IS NOT NULL",
                default => throw new InvalidArgumentException("Operator [$op] does not accept NULL"),
            };
        }
        if ($op === 'in' || $op === 'not in') {
            $list = (array) $operand;
            if (!$list) {
                return $op === 'in' ? '1 = 0' : '1 = 1';
            }
            return $column . ($op === 'in' ? ' IN ' : ' NOT IN ') . '(' . implode(', ', array_map($escapeValue, $list)) . ')';
        }
        $sqlOp = match ($op) {
            '!=' => '<>',
            'like' => 'LIKE',
            'not like' => 'NOT LIKE',
            default => $op,
        };
        return "$column $sqlOp " . $escapeValue($operand);
    }

    private function compare(mixed $actual, string $op, mixed $operand): bool
    {
        if ($operand === null) {
            return $op === '=' ? $actual === null : $actual !== null;
        }
        return match ($op) {
            // Нестрогое сравнение, как в SQL: '5' = 5
            '=' => $actual !== null && $actual == $operand,
            '!=', '<>' => $actual !== null && $actual != $operand,
            '>' => $actual !== null && $actual > $operand,
            '>=' => $actual !== null && $actual >= $operand,
            '<' => $actual !== null && $actual < $operand,
            '<=' => $actual !== null && $actual <= $operand,
            'in' => $actual !== null && in_array($actual, (array) $operand),
            'not in' => $actual !== null && !in_array($actual, (array) $operand),
            'like' => $actual !== null && self::like((string) $actual, (string) $operand),
            'not like' => $actual !== null && !self::like((string) $actual, (string) $operand),
        };
    }

    private static function like(string $value, string $pattern): bool
    {
        $regex = '/^' . strtr(preg_quote($pattern, '/'), ['%' => '.*', '_' => '.']) . '$/iu';
        return (bool) preg_match($regex, $value);
    }
}
