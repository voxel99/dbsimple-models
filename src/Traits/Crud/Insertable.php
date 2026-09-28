<?php

namespace Jam\Models\Traits\Crud;

use Jam\Models\DummyModel;
use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\Utils\Code;

trait Insertable
{
    public function insertInternal(array $ins, $ignore = false, $on_duplicate_key_update = false)
    {
        if (Code::isImplements($this, DummyModel::class)) {
            throw new ModelException('DummyModel is readonly');
        }
        $duplicate_expr = '';
        $duplicate_arr = [];

        if (isset($ins[0])) {
            // multy insert
            foreach ($ins as $k => $_ins) {
                $ins[$k] = $this->fire(Model::EVENT_CREATING, $_ins);
                if (!$ins[$k]) {
                    $ins = false;
                    break;
                }
                $ins[$k] = static::convertRecordsBeforeSaveToDb($ins[$k]);
            }
        } else {
            // Обработчик creating может вернуть false — вставка отменяется
            $ins = $this->fire(Model::EVENT_CREATING, $ins);
            $ins = $ins ? static::convertRecordsBeforeSaveToDb($ins) : false;
        }

        $id = 0;

        if ($ins) {
            if (isset($ins[0])) {
                $fields = array_keys($ins[0]);
                $values = array_values($ins);
            } else {
                $fields = array_keys($ins);
                $values = array_values($ins);
            }


            if ($on_duplicate_key_update) {
                foreach ($fields as $f) {
                    $duplicate_arr[] = '`' . $f . '` = VALUES(`' . $f . '`)';
                }

                /*
                    http://dev.mysql.com/doc/refman/5.0/en/insert-on-duplicate.html
                    If a table contains an AUTO_INCREMENT column and INSERT ... UPDATE inserts a row, the LAST_INSERT_ID()
                    function returns the AUTO_INCREMENT value. If the statement updates a row instead, LAST_INSERT_ID()
                    is not meaningful. However, you can work around this by using LAST_INSERT_ID(expr).
                    Suppose that id is the AUTO_INCREMENT column. To make LAST_INSERT_ID() meaningful for updates,
                    insert rows as follows:

                    INSERT INTO table (a,b,c) VALUES (1,2,3) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), c=3;
                */
                $duplicate_arr[] = '`' . $this->pk() . '` = LAST_INSERT_ID(`' . $this->pk() . '`)';
                $duplicate_expr = " ON DUPLICATE KEY UPDATE " . implode(", ", $duplicate_arr);
            }

            $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '')
                . 'INTO ?_' . $this->table()
                . ' (?#) VALUES (?a)'
                . $duplicate_expr;
            if ($on_duplicate_key_update) {
                $this->db->query('SET SESSION sql_mode = "NO_ENGINE_SUBSTITUTION"');
            }
            $id = $this->db->query(
                $sql,
                $fields,
                $values
            );
            if ($on_duplicate_key_update) {
                $this->db->query('SET SESSION sql_mode = @@GLOBAL.sql_mode');
            }
            if (!isset($ins[0])) {
                $this->fire(Model::EVENT_CREATED, $id, $ins);
            }
        }
        return $id;
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
