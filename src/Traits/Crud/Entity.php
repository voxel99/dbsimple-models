<?php

namespace Jam\Models\Traits\Crud;

use Jam\Models\Utils\CamelCase;
use Jam\Models\Utils\Code;

trait Entity
{
    protected $pk = "id";
    protected $table;
    protected $alias = "";

    public function table()
    {
        return $this->table ?: strtolower(CamelCase::from(Code::classBasename($this)));
    }

    public function alias($alias)
    {
        $this->alias = $alias;
        return $this;
    }

    public function getAlias()
    {
        return $this->alias;
    }

    public function pk()
    {
        return $this->pk;
    }

    /**
     * Задать id для выборки
     *
     * @param integer|array $id Идентификатор модели
     * @return $this
     */
    public function id($id)
    {
        $pk = ($this->alias ? $this->alias . '.' : '') . $this->pk;
        if (is_array($id)) {
            return $this->where([$pk => array_values($id)]);
        }
        $this->{$this->pk} = $id;
        return $this->where([$pk => $id]);
    }
}
