<?php

namespace Jam\Models\Storage;

/**
 * Снимок состояния построителя запроса — то, что модель передаёт хранилищу.
 *
 * Условия (where) — список элементов двух видов:
 *   Criteria                       — условие-массив, понятное любому хранилищу;
 *   array{0: string, ...mixed}     — SQL-фрагмент с плейсхолдерами DbSimple (только SQL-хранилища).
 */
final class Query
{
    /**
     * @param array<int, Criteria|array<int, mixed>> $where
     * @param array<int, string>|null $fields  Поля выборки (null — все)
     * @param array<string, array<int, mixed>> $joins ['JOIN' => [...], 'LEFT JOIN' => [...], 'RIGHT JOIN' => [...]]
     * @param string|null $softDeleteColumn    Колонка SoftDeletes, если нужно отсечь удалённые записи
     */
    public function __construct(
        public readonly string $table,
        public readonly string $alias = '',
        public readonly array $where = [],
        public readonly ?array $fields = null,
        public readonly array $joins = [],
        public readonly string $groupBy = '',
        public readonly mixed $orderBy = null,
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
        public readonly string $lock = '',
        public readonly ?string $softDeleteColumn = null,
    ) {
    }

    /** Есть ли в запросе SQL-фрагменты (их понимают только SQL-хранилища) */
    public function hasRawSql(): bool
    {
        foreach ($this->where as $condition) {
            if (!$condition instanceof Criteria) {
                return true;
            }
        }
        return $this->joins !== [] || $this->groupBy !== '' || $this->lock !== '';
    }
}
