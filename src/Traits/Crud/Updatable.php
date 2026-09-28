<?php

namespace Jam\Models\Traits\Crud;

use Exception;
use Jam\Models\Model;
use Jam\Models\Utils\ArrayHelper;

trait Updatable
{
    public function update($fields = "")
    {
        $this->assertWritable();
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
            // Первичный ключ не обновляем; если кроме него менять нечего — запроса нет
            unset($upd[$this->pk]);
            if ($upd) {
                // `?`, а не `?d`: первичный ключ может быть строковым
                $this->db->query(
                    'UPDATE ?_'
                    . $this->table()
                    . ' SET ?a WHERE ?# = ?',
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
            'UPDATE ' . ($delayed ? 'DELAYED ' : '') . '?_' . $this->table() . ' SET ?# = ?#+?d WHERE ?# = ?',
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
