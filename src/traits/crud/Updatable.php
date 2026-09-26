<?php

namespace Jam\Models\Traits\Crud;

use Exception;
use Jam\Models\DummyModel;
use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Utils\Code;

trait Updatable
{
/*
    public function replace(array $ins) {
        if (Code::isImplements($this, DummyModel::class)) {
            throw new ModelException('DummyModel is readonly');
        }

        if (isset($ins[0])) {
            foreach ($ins as $k => $_ins) {
                $ins[$k] = static::convertRecordsBeforeSaveToDb($_ins);
            }
            $fields = array_keys($ins[0]);
            $values = array_values($ins);
        } else {
            $ins = static::convertRecordsBeforeSaveToDb($ins);
            $fields = array_keys($ins);
            $values = array_values($ins);
        }

        $sql = 'REPLACE INTO ?_' . $this->table() . ' (?#) VALUES (?a)';
        $this->db->query(
            $sql,
            $fields,
            $values
        );
    }
*/
    public function update($fields = "")
    {
        if (Code::isImplements($this, DummyModel::class)) {
            throw new ModelException('DummyModel is readonly');
        }
        $id = $this->{$this->pk};
        $upd = $this->toArray([
            self::FLAG_DEPENDENCIES => false,
            self::FLAG_HIDDEN => true,
            self::FLAG_INTERNAL_DATABASE => true,
            self::FLAG_UPDATABLE => true,
        ]);

        if ($fields) {
            if (!is_array($fields)) {
                $fields = ArrayHelper::stringCommasToArray($fields);
            }
            $upd = array_intersect_key($upd, array_flip($fields));
        }

        $upd = $this->fire(Model::EVENT_UPDATING, $id, $upd);
        $skipUpdate = !empty($upd[static::SKIP_UPDATE]) && $upd[static::SKIP_UPDATE] === static::SKIP_UPDATE;
        if (!$skipUpdate) {
            $upd = static::convertRecordsBeforeSaveToDb($upd);
            if ($upd) {
                unset($upd[$this->pk]);
                $this->db->query(
                    'UPDATE ?_'
                    . $this->table()
                    . ' SET ?a WHERE ?# = ?d',
                    $upd,
                    $this->pk(),
                    $id
                );
                $this->fire(Model::EVENT_UPDATED, $id, $upd);
            }
        }
        return $id;
    }

    public function incByUpdate($field, $value = 1, $delayed = false)
    {
        $id = $this->{$this->pk};
        $this->db->query(
            'UPDATE ' . ($delayed ? 'DELAYED ' : '') . '?_' . $this->table() . ' SET ?# = ?#+?d WHERE ?# = ?d',
            $field,
            $field,
            $value,
            $this->pk(),
            $id
        );
        $this->{$field} = $this->{$field} + $value;
    }

    public static function makeNotUpdateble($rec)
    {
        if (is_object($rec)) {
            $rec->{static::SKIP_UPDATE} = static::SKIP_UPDATE;
        } elseif (is_array($rec)) {
            $rec[static::SKIP_UPDATE] = static::SKIP_UPDATE;
        } else {
            throw new Exception('Can\'t make not updatable record. It must be array or object.');
        }
        return $rec;
    }
}
