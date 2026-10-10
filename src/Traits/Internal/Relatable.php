<?php

namespace Jam\Models\Traits\Internal;

use Exception;
use Jam\Models\Model;
use Jam\Models\ModelAbstract;
use Jam\Models\ModelException;
use Jam\Models\ModelList;
use Jam\Models\Relation;

trait Relatable
{
    public static $jamRelationsClasses = [];
    /**
     * @var array<class-string, bool> см. jamDetectPlainRelations()
     */
    private static array $jamPlainRelations = [];
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
    /**
     * Алиасы связей, данные которых получены (через with() или fromArray), в том числе пустые
     * @var array<string, true>
     */
    private array $jamLoadedRelations = [];

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
        // Присваивание computed-свойству молча игнорируется (DynamicProps::__set),
        // поэтому загруженные через with() данные такой связи терялись бы
        $isComputed = $this->isComputed($alias);
        if ($throwException && $isComputed) {
            throw new ModelException(sprintf('Relation alias and computed property have equal names: %s (Model: %s)', $alias, get_class($this)));
        }

        return !$isField && !$isComputed;
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

    /**
     * Загружена ли связь. В отличие от проверки на null, отличает «загружено, но пусто»
     * (with() не нашёл связанных записей) от «не загружено» (связь не запрашивалась).
     */
    public function isRelationLoaded(string $alias): bool
    {
        return isset($this->jamLoadedRelations[$alias]) || $this->$alias instanceof ModelAbstract;
    }

    /**
     * @internal Вызывается при гидрации модели (ConvertableHelperFromArray)
     */
    public function markRelationLoaded(string $alias): void
    {
        $this->jamLoadedRelations[$alias] = true;
    }

    private function forgetRelationLoaded(string $alias): void
    {
        unset($this->jamLoadedRelations[$alias]);
    }

    /**
     * Дерево загруженных связей для serialize(): toArray() пустые связи не выводит,
     * поэтому факт загрузки переносится отдельно, включая вложенные модели.
     * Формат: [alias => [] | ['m' => дерево] | ['l' => [ключ => дерево]]].
     *
     * @internal
     * @return array<string, array<string, mixed>>
     */
    public function exportLoadedRelations(): array
    {
        $tree = [];
        foreach (array_keys($this->getAllRelations()) as $alias) {
            if (!$this->isRelationLoaded($alias)) {
                continue;
            }
            $node = [];
            $value = $this->$alias;
            if ($value instanceof self) {
                if ($sub = $value->exportLoadedRelations()) {
                    $node['m'] = $sub;
                }
            } elseif ($value instanceof ModelList) {
                foreach ($value as $k => $item) {
                    if ($item instanceof self && ($sub = $item->exportLoadedRelations())) {
                        $node['l'][$k] = $sub;
                    }
                }
            }
            $tree[$alias] = $node;
        }
        return $tree;
    }

    /**
     * @internal Восстанавливает дерево из exportLoadedRelations() после unserialize()
     * @param array<string, array<string, mixed>> $tree
     */
    public function importLoadedRelations(array $tree): void
    {
        foreach ($tree as $alias => $node) {
            $this->jamLoadedRelations[$alias] = true;
            $value = $this->$alias;
            if (isset($node['m']) && $value instanceof self) {
                $value->importLoadedRelations($node['m']);
            } elseif (isset($node['l']) && $value instanceof ModelList) {
                foreach ($node['l'] as $k => $sub) {
                    if (($item = $value[$k]) instanceof self) {
                        $item->importLoadedRelations($sub);
                    }
                }
            }
        }
    }

    public function getLocalKeys()
    {
        $keys = array_map(static fn(Relation $R) => $R->localKey(), array_values($this->getAllRelations()));
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

    public function getRelationByAlias($prop): ?Relation
    {
        // Связи хранятся по ключу-алиасу
        if (!is_string($prop)) {
            return null;
        }
        if (is_numeric($prop) || !(self::$jamPlainRelations[static::class] ?? $this->jamDetectPlainRelations())) {
            return $this->getAllRelations()[$prop] ?? null;
        }
        return $this->jamRelationByAliasDirect($prop);
    }

    /**
     * Поиск отношения без array_merge всех отношений (горячий путь __set/fromArray).
     * Порядок как у getAllRelations(): при совпадении алиасов побеждает последний массив.
     * Только для нечисловых алиасов: целочисленные ключи array_merge перенумеровывает.
     */
    private function jamRelationByAliasDirect(string $prop): ?Relation
    {
        if (array_key_exists($prop, $this->belongs)) {
            return $this->belongs[$prop] ?? null;
        }
        if (array_key_exists($prop, $this->hasOne)) {
            return $this->hasOne[$prop] ?? null;
        }
        return $this->hasMany[$prop] ?? null;
    }

    /**
     * Класс модели не переопределяет getAllRelations()/getRelationByAlias()/isRelationProp(),
     * и их можно обходить на горячем пути. Иначе — прежний путь через эти методы.
     */
    private function jamDetectPlainRelations(): bool
    {
        $plain = true;
        foreach (['getAllRelations', 'getRelationByAlias', 'isRelationProp'] as $method) {
            $plain = $plain && (new \ReflectionMethod($this, $method))->getDeclaringClass()->getName() === self::class;
        }
        return self::$jamPlainRelations[static::class] = $plain;
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
        if (is_string($prop) && !is_numeric($prop) && (self::$jamPlainRelations[static::class] ?? $this->jamDetectPlainRelations())) {
            // Вызывается на каждый ключ гидрации — без лишнего вызова getRelationByAlias().
            // Если ни в одном массиве нет непустого значения, отношения нет; при совпадении
            // алиасов в нескольких массивах решает порядок getAllRelations().
            if (isset($this->belongs[$prop])) {
                return true;
            }
            if (!isset($this->hasOne[$prop]) && !isset($this->hasMany[$prop])) {
                return false;
            }
            return (bool) $this->jamRelationByAliasDirect($prop);
        }
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
        return array_keys($this->getAllRelations());
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
                // Ключ модели пуст (например, INSERT IGNORE ничего не вставил) — пустой
                // внешний ключ связанным моделям и pivot-строкам не проставляем
                if (static::isEmptyInsertId($this->$localKey)) {
                    continue;
                }
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
