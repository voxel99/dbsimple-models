<?php

namespace Jam\Models\Traits;

use Jam\Models\Model;

trait Timestamps
{
    protected $columnCreated = "created_at";
    protected $columnUpdated = "updated_at";

    /**
     * Boot the updating timestamps trait for a model.
     *
     * @return void
     */
    public function bootTimestamps()
    {
        $this->on(Model::EVENT_CREATING, function ($data) {
            if (empty($data[$this->getCreatedAtColumn()])) {
                $pk = $this->pk();
                if (empty($this->{$pk}) && empty($data[$pk])) {
                    if (!empty($this->{$this->getCreatedAtColumn()})) {
                        $data[$this->getCreatedAtColumn()] = $this->{$this->getCreatedAtColumn()};
                    } else {
                        $this->{$this->getCreatedAtColumn()} = $data[$this->getCreatedAtColumn()] = static::freshTimestamp();
                    }
                }
            }
            return $data;
        });

        $this->on(Model::EVENT_UPDATING, function ($id, $data) {
            $this->{$this->getUpdatedAtColumn()} = $data[$this->getUpdatedAtColumn()] = static::freshTimestamp();
            return $data;
        });
    }


    /**
     * Set the value of the "created at" attribute.
     *
     * @param mixed $value
     * @return $this
     */
    public function setCreatedAt($value)
    {
        $this->{$this->columnCreated} = $value;
        return $this;
    }

    /**
     * Set the value of the "updated at" attribute.
     *
     * @param mixed $value
     * @return $this
     */
    public function setUpdatedAt($value)
    {
        $this->{$this->columnUpdated} = $value;

        return $this;
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function getCreatedAtColumn()
    {
        return $this->columnCreated;
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function getUpdatedAtColumn()
    {
        return $this->columnUpdated;
    }
}
