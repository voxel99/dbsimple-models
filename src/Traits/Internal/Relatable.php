<?php

namespace Jam\Models\Traits\Internal;

use Exception;
use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\ModelList;
use Jam\Models\Relation;

trait Relatable
{
    public static $jamRelationsClasses = [];
    /**
     * Отношения типа один-ко-многим
     * @var array of Relation
     */
    protected $hasMany = [];
    /**
     * Отношения типа один-к-одному
     * @var array of Relation
     */
    protected $hasOne = [];
    /**
     * Отношения типа принадлежит
     * @var array of Relation
     */
    protected $belongs = [];

    public function getRelations($type)
    {
        if (in_array($type, ['hasMany', 'hasOne', 'belongs'])) {
            return $this->{$type};
        }
        throw new Exception("Incorrect relation type: " . $type);
    }

    public function setRelations($type, array $relations)
    {
        if (in_array($type, ['hasMany', 'hasOne', 'belongs'])) {
            $this->{$type} = $relations;
        } else {
            throw new Exception("Incorrect relation type: " . $type);
        }
    }

    private function checkAlias($alias, $throwException = true)
    {
        $isField = $this->isField($alias);
        if ($throwException && $isField) {
            throw new ModelException(sprintf('Ralation alias and field name have equal names: %s (Model: %s)', $alias, get_class($this)));
        }

        return !$isField;
    }

    public function hasOne($class, $alias, $localKey = "", $otherKey = "", $wheres = [])
    {
        if ($this->checkAlias($alias)) {
            $this->hasOne[$alias] = new Relation($class, $alias, Relation::HAS_ONE, $localKey, $otherKey, $wheres);
        }
    }

    public function hasMany($class, $alias, $localKey, $otherKey, $wheres = [], $pivotClass = null, $pivotLocalKey = '', $pivotOtherKey = '')
    {
        if ($this->checkAlias($alias)) {
            $this->hasMany[$alias] = new Relation($class, $alias, Relation::HAS_MANY, $localKey, $otherKey, $wheres, $pivotClass, $pivotLocalKey, $pivotOtherKey);
        }
    }

    public function belongs($class, $alias, $localKey, $otherKey, $wheres = [])
    {
        if ($this->checkAlias($alias)) {
            $this->belongs[$alias] = new Relation($class, $alias, Relation::BELONGS, $localKey, $otherKey, $wheres);
        }
    }

    public function classCallback(array $classesByType, string $typeProp = "type"): \Closure
    {
        return function ($obj) use ($classesByType, $typeProp) {
            $type = $obj && isset(((object)$obj)->{$typeProp}) ? ((object)$obj)->{$typeProp} : $this->{$typeProp};
            return $classesByType[(string) $type] ?? '';
        };
    }

    /**
     * Получить все отношения
     * @return array<string, Relation> All model relations
     */
    public function getAllRelations(): array
    {
        return array_merge($this->hasMany, $this->hasOne, $this->belongs/*, $this->belongs_many*/);
    }

    /**
     * Получить проинициализированные отношения
     * @return array<string, Relation> Initialized relations only
     */
    public function getInitedRelations()
    {
        $relations = $this->getAllRelations();
        $ret = [];
        foreach ($relations as $alias => $R) {
            if (is_null($this->$alias)) {
                continue;
            }
            $ret[$alias] = $R;
        }
        return $ret;
    }

    public function getLocalKeys()
    {
        $relations = $this->getAllRelations();
        $keys = [];
        foreach ($relations as $relation) {
            $keys[] = $relation->localKey();
        }
        $keys[] = $this->pk();
        return array_unique($keys);
    }

    /**
     * Получить отношения HAS
     * @return array<string, Relation> HasMany and HasOne relations
     */
    public function getHasRelations()
    {
        return array_merge($this->hasMany, $this->hasOne);
    }

    public function getBelongsRelations()
    {
        return $this->belongs;
    }

    public function getRelationByAlias($prop)
    {
        if (is_string($prop)) {
            $relations = $this->getAllRelations();
            /** @var Relation $R */
            foreach ($relations as $R) {
                if ($R->alias() == $prop) {
                    return $R;
                }
            }
        }
        return null;
    }

    public function getRelationByClass($className, $row = null)
    {
        if (is_string($className)) {
            $relations = $this->getAllRelations();
            /** @var Relation $R */
            foreach ($relations as $R) {
                if ($R->getClass($row) == $className) {
                    return $R;
                }
            }
        }
        return null;
    }

    public function isRelationProp($prop)
    {
        return (bool) $this->getRelationByAlias($prop);
    }

    public function getRelationClasses()
    {
        $relations = $this->getAllRelations();
        $classes = [];
        /** @var Relation $R */
        foreach ($relations as $R) {
            $classes[$R->alias()] = function ($row = null) use ($R) {
                return $R->getClass($row);
            };
        }
        if ($this->getPivotClass()) {
            $classes[self::PIVOT_FIELD] = function () {
                return $this->getPivotClass();
            };
        }
        return $classes;
    }

    public function getPivotClass(): string
    {
        /** @var Relation $R */
        foreach ($this->hasMany as $R) {
            // TODO Если несколько возможных pivots надо определить, чей именно запрашивается
            if ($R->getPivotClass()) {
                return $R->getPivotClass();
            }
        }
        return '';
    }

    public function getRelationAliases()
    {
        $relations = $this->getAllRelations();
        $aliases = [];
        /** @var Relation $R */
        foreach ($relations as $R) {
            $aliases[] = $R->alias();
        }
        return $aliases;
    }

    /**
     * @param $prop
     * @param null $row
     * @return Model
     * @throws ModelException
     */
    public function getRelationModelByAlias($prop, $row = null)
    {
        $classes = $this->getRelationClasses();
        if (!isset($classes[$prop])) {
            throw new ModelException("Неизвестное свойство: " . $prop);
        }
        $className = $classes[$prop]($row);
        return new $className();
    }

    public function getRelationList($deep = false)
    {
        $ret = [];
        $allrelations = $this->getAllRelations();
        /** @var Relation $R */
        foreach ($allrelations as $R) {
            $alias = $R->alias();
            if ($this->$alias instanceof ModelList) {
                foreach ($this->$alias as $model) {
                    $ret[$R->getClass()][] = $model;
                }
                if ($deep) {
                    $relations = $this->$alias->getRelationList();
                    foreach ($relations as $class => $models) {
                        foreach ($models as $model) {
                            $ret[$class][] = $model;
                        }
                    }
                }
            } elseif ($this->$alias instanceof Model) {
                $ret[$R->getClass()][] = $this->$alias;
            }
        }
        return $ret;
    }

    public function setRelationKeys(): void
    {
        foreach ([$this->hasMany, $this->hasOne] as &$relations) {
            /**
             * @var Relation $R
             */
            foreach ($relations as &$R) {
                $alias = $R->alias();
                $localKey = $R->localKey();
                $otherKey = $R->otherKey();
                $pivot = $R->getPivot();
                if ($pivot) {
                    $pivotLocalKey = $R->pivotLocalKey(); // post_id
                    $pivotOtherKey = $R->pivotOtherKey(); // tag_id

                    $pivot_class = get_class($pivot);
                    $pivotModelList = new ModelList($pivot_class);

                    if ($this->$alias) {
                        // $this->tags as Tag
                        foreach ($this->$alias as $model) {
                            $pobj = new $pivot_class();
                            $pobj->$pivotOtherKey = $model->$otherKey;
                            $pobj->$pivotLocalKey = $this->$localKey;
                            $pivotModelList[] = $pobj;
                        }
                        $R->setPivotModelList($pivotModelList);
                    }

                    // Например, $this->tags для модели Post
                    // foreach (as $item)
                    //     $item->$otherKey = $this->$localKey;
                } else {
                    // Например, $this->attaches для модели Post
                    if ($this->$alias instanceof ModelList) {
                        // foreach ($this->attaches as $model)
                        //     $model->post_id = $this->id
                        foreach ($this->$alias as $model) {
                            $model->$otherKey = $this->$localKey;
                        }
                    } elseif ($this->$alias instanceof Model) {
                        // Например, $this->profile для модели User
                        // $this->profile->user_id = $this->id
                        $this->$alias->$otherKey = $this->$localKey;
                    }
                }
            }
        }
    }
}
