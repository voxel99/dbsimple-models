<?php

namespace Jam\Models\Storage;

use Jam\Models\Model;

/**
 * Хранилище моделей: всё, что модель делает с базой данных.
 *
 * Модель отвечает за поля, типы, связи, события и сериализацию, хранилище — только за
 * чтение и запись строк. Реализации: SqlStorage (DbSimple: MySQL, PostgreSQL, SQLite),
 * MemoryStorage (DummyModel). Своё хранилище (например, MongoDB) подключается
 * переопределением Model::createStorage().
 *
 * Строки — ассоциативные массивы «как в БД» (db-представление, без кастов модели).
 */
interface StorageInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function select(Model $model, Query $query): array;

    /**
     * @param 'COUNT'|'SUM'|'AVG'|'MIN'|'MAX' $function
     */
    public function aggregate(Model $model, Query $query, string $function, string $field): mixed;

    /**
     * Вставка одной или нескольких строк одним запросом.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param bool $ignore Пропускать строки, нарушающие уникальность
     * @param bool $upsert Обновлять существующую строку при совпадении ключа
     * @return int|string ID вставленной строки (для одиночной вставки)
     */
    public function insert(Model $model, array $rows, bool $ignore = false, bool $upsert = false): int|string;

    /**
     * @param int|string|array<int, int|string> $id Первичный ключ или список ключей
     * @param array<string, mixed> $data
     * @return int Количество изменённых строк
     */
    public function update(Model $model, int|string|array $id, array $data): int;

    /**
     * Атомарно увеличить поле: field = field + $by
     */
    public function increment(Model $model, int|string $id, string $field, int|float $by): int;

    /**
     * @param int|string|array<int, int|string> $id
     * @return int Количество удалённых строк
     */
    public function delete(Model $model, int|string|array $id): int;
}
