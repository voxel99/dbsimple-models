<?php

namespace Jam\Models;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Jam\Models\Traits\Collection;
use Jam\Models\Utils\ArrayHelper;
use Traversable;

/**
 * @template T of Model
 * @implements ArrayAccess<int|string, T>
 * @implements IteratorAggregate<int|string, T>
 */
class ModelList extends ModelAbstract implements ArrayAccess, Countable, IteratorAggregate, IRelationList
{
    use Collection;

    /** @var array<int|string, T> */
    private array $jamList = [];

    /** @var class-string<T> */
    private string $jamClass;

    public function __construct($class, $data = null)
    {
        $this->jamClass = $class;
        parent::__construct();
        if ($data) {
            $this->fromArray((array)$data);
        }
    }

    public function offsetGet($offset): mixed
    {
        if (isset($this->jamList[$offset])) {
            return $this->jamList[$offset];
        }
        return null;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->jamList[$offset]);
    }

    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->jamList[] = $value;
        } else {
            $this->jamList[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->jamList[$offset]);
    }

    public function count(): int
    {
        return count($this->jamList);
    }

    public function __get($key)
    {
        return $this->jamList[$key] ?? null;
    }

    public function size(): int
    {
        return $this->count();
    }

    public function getIterator(): Traversable
    {
        return (function () {
            foreach ($this->jamList as $key => $val) {
                yield $key => $val;
            }
        })();
    }

    public function set($list): void
    {
        $this->jamList = $list;
    }

    public function push(Model $model): void
    {
        $this->jamList[] = $model;
    }

    public function first(): ?Model
    {
        return !empty($this->jamList) ? reset($this->jamList) : null;
    }

    public function getClass(): string
    {
        return $this->jamClass;
    }

    public function each($callback): self
    {
        array_map(function (Model $model) use ($callback) {
            return $callback($model);
        }, $this->jamList);
        return $this;
    }

    /**
     * Возвращает плоский список значений одного поля по всем элементам коллекции
     * (аналог array_column, но без полной сериализации через toArray()).
     *
     * @return array<int, mixed>
     */
    public function column(string $key): array
    {
        $ret = [];
        foreach ($this->jamList as $model) {
            $ret[] = $model->{$key};
        }
        return $ret;
    }
    public function fromArray(array $array): void
    {
        foreach ($array as $k => $v) {
            if ($v instanceof Model) {
                $this->jamList[$k] = $v;
            } else {
                $this->jamList[$k] = new $this->jamClass($v);
            }
        }
    }
    public function toArray(
        $flags = null,
        $fields = null,
        $with = null,
        $exclude = null,
        $cutFields = false,
        $debug = false
    ): array {
        $with = array_merge($with ?: [], $this->getWithArray(true));
        return array_map(function ($v) use ($with, $debug, $cutFields, $flags, $exclude, $fields) {
            /** @var Model $v */
            return $v->toArray($flags, $fields, $with, $exclude, $cutFields, $debug);
        }, $this->jamList);
    }

    public function toObject(
        $flags = null,
        $fields = null,
        $with = null,
        $exclude = null,
        $cutFields = false,
        $debug = false
    ): array {
        $with = array_merge($with ?: [], $this->getWithArray(true));
        return array_map(function ($v) use ($with, $debug, $cutFields, $flags, $exclude, $fields) {
            /** @var Model $v */
            return $v->toObject($flags, $fields, $with, $exclude, $cutFields, $debug);
        }, $this->jamList);
    }

    public function isEmpty(): bool
    {
        return $this->size() == 0;
    }

    private function getModel(): Model
    {
        $cls = $this->getClass();
        $M = new $cls();
        $this->inheritModel($M);
        return $M;
    }

    private function getInsertValues(): array
    {
        $values = $this->toArray([
            self::FLAG_DEPENDENCIES => false,
            self::FLAG_EMPTY => false,
        ]);
        return ArrayHelper::fillAbsentValues($values, static::NULL_VALUE);
    }

    /**
     * @throws ModelException
     */
    private function insertInternal($ignore = false, $on_duplicate_key_update = false): void
    {
        $this->getModel()->insertInternal($this->getInsertValues(), $ignore, $on_duplicate_key_update);
    }

    // public function replace (): void {
    //    $this->getModel()->replace($this->getInsertValues());
    // }

    /**
     * @throws ModelException
     */
    public function insert(): void
    {
        $this->insertInternal();
    }

    /**
     * @throws ModelException
     */
    public function insertI(): void
    {
        $this->insertInternal(true);
    }

    /**
     * @throws ModelException
     */
    public function insertD(): void
    {
        $this->insertInternal(false, true);
    }

    public function getRelationList($deep = false): array
    {
        $ret = [];
        foreach ($this->jamList as $model) {
            $relations = $model->getRelationList($deep);
            foreach ($relations as $class => $models) {
                foreach ($models as $model) {
                    $ret[$class][] = $model;
                }
            }
        }
        return $ret;
    }

    public function setRelationKeys(): void
    {
        foreach ($this->jamList as $model) {
            $model->setRelationKeys();
        }
    }

    public function save(array $params = []): array
    {
        $ret = [];
        /** @var Model $model */
        foreach ($this->jamList as $model) {
            $this->inheritModel($model);
            $ret[] = $model->save($params);
        }
        return $ret;
    }

    public function delete($withRelations = false): void
    {
        /** @var Model $model */
        foreach ($this->jamList as $model) {
            $this->inheritModel($model);
            $model->delete($withRelations);
        }
    }

    public function forceDelete($withHASRelations = false, $withBELONGSRelation = false): void
    {
        /** @var Model $model */
        foreach ($this->jamList as $model) {
            $this->inheritModel($model);
            $model->forceDelete($withHASRelations, $withBELONGSRelation);
        }
    }

    public function __toString(): string
    {
        return "List of {" . $this->jamClass . "}";
    }

    public function serialize(): string
    {
        $serialized = [];
        $props = get_object_vars($this);
        $include = ['jamList', 'jamClass'];
        foreach ($include as $prop) {
            if (!empty($props[$prop])) {
                $serialized[$prop] = $props[$prop];
            }
        }
        return serialize($serialized);
    }

    public function unserialize($serialized): void
    {
        $props = unserialize($serialized);
        foreach ($props as $prop => $val) {
            $this->{$prop} = $val;
        }
        $this->db($this->jamConnection);
    }

    public function excludeFields($excludes): static
    {
        foreach ($this->jamList as $k => $v) {
            $this->jamList[$k]->excludeFields($excludes);
        }
        return $this;
    }

    public function updateRaw($data): void
    {
        /** @var Model $M */
        foreach ($this->jamList as $M) {
            foreach ($data as $k => $v) {
                $M->{$k} = $v;
            }
            $M->update(array_keys($data));
        }
    }
    /*
        function setKeyBy($keyby) {
            $this->keyby = $keyby;
        }

        function getKeyBy($keyby) {
            return $this->keyby;
        }
    */
}
