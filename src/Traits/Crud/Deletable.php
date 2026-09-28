<?php

namespace Jam\Models\Traits\Crud;

use Jam\Models\Model;
use Jam\Models\Traits\SoftDeletes;
use Jam\Models\Utils\Code;

trait Deletable
{
    public function delete($withHASRelations = false, $withBELONGSRelation = false)
    {
        $this->assertWritable();
        $id = $this->{$this->pk()};
        $stat = true;

        $relations = [];
        if ($withHASRelations) {
            $relations = array_merge($relations, $this->getHasRelations());
        }
        if ($withBELONGSRelation) {
            $relations = array_merge($relations, $this->getBelongsRelations());
        }

        if ($relations) {
            foreach ($relations as $prop => $Relation) {
                if (isset($this->$prop)) {
                    if (Code::isUses(get_class($this->$prop), SoftDeletes::class) && $this->isForceDeleting()) {
                        $this->$prop->forceDelete($withHASRelations, $withBELONGSRelation);
                    } else {
                        $this->$prop->delete($withHASRelations, $withBELONGSRelation);
                    }
                }
            }
        }
        if ($this->fire(Model::EVENT_DELETING, $this)) {
            $stat = $this->storage()->delete($this, $id);
            $this->fire(Model::EVENT_DELETED, $this);
        }
        return $stat;
    }

    public function deleteListByPk(array $ids)
    {
        if ($ids) {
            $recs = static::instance()->where([$this->pk() => array_values($ids)])->collection();
            foreach ($recs as $rec) {
                $rec->delete();
            }
        }
    }

    public function deleteWhere(...$args)
    {
        $recs = static::instance()->where(...$args)->collection();
        foreach ($recs as $rec) {
            $rec->forceDelete();
        }
    }
}
