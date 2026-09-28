<?php

namespace Jam\Models\Traits\Crud;

use Jam\Models\Model;

trait Insertable
{
    /**
     * Вставка одной строки (ассоциативный массив) или нескольких (список массивов) одним запросом.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $ins
     * @return int|string ID вставленной строки (для одиночной вставки)
     */
    public function insertInternal(array $ins, $ignore = false, $on_duplicate_key_update = false)
    {
        $this->assertWritable();

        $isMulti = isset($ins[0]);
        $rows = $this->prepareInsertRows($isMulti ? $ins : [$ins]);
        if (!$rows) {
            return 0;
        }

        $id = $this->storage()->insert($this, array_values($rows), (bool) $ignore, (bool) $on_duplicate_key_update);

        if (!$isMulti) {
            // Для ключа, заданного явно (в т.ч. строкового), lastInsertId() не информативен
            if (!$id && isset($rows[0][$this->pk()])) {
                $id = $rows[0][$this->pk()];
            }
            $this->fire(Model::EVENT_CREATED, $id, $rows[0]);
        }
        return $id;
    }

    /**
     * Прогоняет строки через событие creating и конвертирует для БД.
     * Если обработчик вернул false хотя бы для одной строки — вставка отменяется целиком.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function prepareInsertRows(array $rows): array
    {
        foreach ($rows as $k => $row) {
            $row = $this->fire(Model::EVENT_CREATING, $row);
            if (!$row) {
                return [];
            }
            $rows[$k] = static::convertRecordsBeforeSaveToDb($row);
        }
        return $rows;
    }

    public function doInsert($ignore = false, $on_duplicate_key_update = false)
    {
        $ins = $this->toArray([
            self::FLAG_DEPENDENCIES => false,
            self::FLAG_HIDDEN => true,
            self::FLAG_INTERNAL_DATABASE => true,
            self::FLAG_UPDATABLE => true
        ]);
        if (empty($ins)) {
            $ins = [
                $this->pk() => 0
            ];
        }
        return $this->insertInternal($ins, $ignore, $on_duplicate_key_update);
    }

    /**
     * Insert
     * @return inserted id
     */
    public function insert()
    {
        $id = $this->doInsert();
        $this->id($id);
        return $id;
    }

    public function insertI()
    {
        $id = $this->doInsert(true);
        $this->id($id);
        return $id;
    }

    public function insertD()
    {
        $id = $this->doInsert(false, true);
        $this->id($id);
        return $id;
    }
}
