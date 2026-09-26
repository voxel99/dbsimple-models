<?php

namespace Jam\Models;

use Closure;
use Jam\Models\Traits\Crud\Withable;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Utils\Code;

class Relation
{
    public const HAS_ONE = 'hasOne';
    public const HAS_MANY = 'hasMany';
    public const BELONGS = 'belongs';
    public const BELONGS_MANY = 'belongs_many';

    protected string $type;
    protected string|Closure $class;
    protected string $alias;
    protected ?Model $pivot = null;
    public string $localKey = '';
    public string $otherKey = '';
    protected string $pivotClass = '';
    protected string $pivotLocalKey = '';
    protected string $pivotOtherKey = '';
    protected ?ModelList $pivotModelList = null;

    protected bool $isReturnEmpty = false;
    protected bool $isRequired = true;

    /**
     * Данные, которые надо извлекать из связанной модели
     * @see Withable
     * @var array[ class or alias ]
     *          flags[] - флаги
     *          fields[] - поля
     *          with[] - рекурсивный with
     */
    protected array $with = [];

    /**
     * Поля, которые надо извлекать из связанной модели
     * @var array
     */
    protected array $fields = [];

    /**
     * Поля, которые надо исключать из связанной модели
     * @var array
     */
    protected array $exclude = [];

    /**
     * Значения, которые нужно инициализировать в связной модели
     * @var array
     */
    protected array $wheres = [];

    /**
     * Условия, которые задаются в конструкторе отношения
     * @var array
     */
    protected array $constWheres = [];

    /**
     * Массив флагов для связной модели
     * @var array
     */
    protected array $flags = [];

    public static array $loadedTraits = [];

    protected bool $isClosure;

    public function __construct(
        $classname,
        $alias,
        $type,
        $localKey = "",
        $otherKey = "",
        $constWheres = null,
        $pivotClass = null,
        $pivotLocalKey = '',
        $pivotOtherKey = ''
    ) {
        $thisClass = get_class($this);
        if (!isset(self::$loadedTraits[$thisClass])) {
            // Загружаем все используемые трейты
            $traits = Code::classUsesRecursive($thisClass);
            self::$loadedTraits[$thisClass] = [];
            foreach ($traits as $trait) {
                if (method_exists(get_called_class(), $method = 'boot' . Code::classBasename($trait))) {
                    self::$loadedTraits[$thisClass][] = $method;
                }
            }
        }

        if (!empty(self::$loadedTraits[$thisClass])) {
            foreach (self::$loadedTraits[$thisClass] as $method) {
                $this->{$method}();
            }
        }

        $this->class = $classname;
        $this->alias = $alias;
        $this->type = $type;
        $this->localKey = $localKey;
        $this->otherKey = $otherKey;
        $this->constWheres = $constWheres ? (array)$constWheres : [];
        $this->setPivot($pivotClass, $pivotLocalKey, $pivotOtherKey);
        $this->isClosure = $classname instanceof Closure;
    }

    public function setRequired($flag): void
    {
        $this->isRequired = $flag;
    }

    public function required(): bool
    {
        return $this->isRequired;
    }

    public function setReturnEmpty($flag): void
    {
        $this->isReturnEmpty = $flag;
    }

    public function localKey(): string
    {
        return $this->localKey;
    }

    public function otherKey(): string
    {
        return $this->otherKey;
    }

    public function pivotLocalKey(): string
    {
        return $this->pivotLocalKey;
    }

    public function pivotOtherKey(): string
    {
        return $this->pivotOtherKey;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function alias(): string
    {
        return $this->alias;
    }

    public function isClassInstanceOfClosure(): bool
    {
        return $this->isClosure;
    }

    public function getClass($row = null): string
    {
        if ($this->isClassInstanceOfClosure()) {
            $invoke = $this->class;
            return $invoke($row);
        }
        return $this->class;
    }

    public function getPivotClass(): string
    {
        return $this->pivotClass;
    }

    public function setPivot($pivot, $pivotLocalKey, $pivotOtherKey)
    {
        if ($pivot instanceof Model) {
            $this->pivot = $pivot;
        } elseif (is_string($pivot)) {
            $this->pivot = new $pivot();
        } elseif ($pivot instanceof Closure) {
            $this->pivot = $this->setPivot($pivot(), $pivotLocalKey, $pivotOtherKey);
        } elseif (is_null($pivot)) {
            $this->pivot = null;
        } else {
            throw new ModelException("Pivot object is not convertable to Model");
        }
        if ($this->pivot) {
            $this->pivotClass = get_class($this->pivot);
            $this->pivotModelList = new ModelList($this->pivotClass);
        } else {
            $this->pivotClass = '';
            $this->pivotModelList = null;
        }
        $this->pivotLocalKey = $pivotLocalKey;
        $this->pivotOtherKey = $pivotOtherKey;
        return $this->pivot;
    }

    /**
     * Получить промежуточную таблицу для связи "много-ко-многим"
     * @return Model|null
     */
    public function getPivot(): ?Model
    {
        return $this->pivot;
    }

    /**
     * @return ModelList|null
     */
    public function getPivotModelList(): ?ModelList
    {
        return $this->pivotModelList;
    }

    public function setPivotModelList(ModelList $list): void
    {
        $pivot = $this->getPivot();
        if (!$pivot || ($list->getClass() !== get_class($pivot))) {
            throw new ModelException(sprintf(
                "Pivot class (%s) not equals to pivot ModelList class (%s)",
                get_class($pivot),
                $list->getClass()
            ));
        }
        $this->pivotModelList = $list;
    }

    public function isTypeBelongs(): bool
    {
        return in_array($this->type(), [self::BELONGS, self::BELONGS_MANY]);
    }

    /**
     * Возвращает TRUE, если отношение подразумевает множественность
     * @return bool
     */
    public function isTypeMany(): bool
    {
        return in_array($this->type(), [self::HAS_MANY, self::BELONGS_MANY]);
    }

    public function setWithArray(array $with): void
    {
        $this->with = $with;
    }

    public function getWithArray(): array
    {
        return $this->with;
    }

    public function setFields(array|string $fields): void
    {
        $this->fields = is_string($fields) ? ArrayHelper::stringCommasToArray($fields) : $fields;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function setExclude(array|string $exclude): void
    {
        $this->exclude = is_string($exclude) ? ArrayHelper::stringCommasToArray($exclude) : $exclude;
    }

    public function getExclude(): array
    {
        return $this->exclude;
    }

    public function setFlags(array $flags): void
    {
        $this->flags = $flags;
    }

    public function getFlags(): array
    {
        return $this->flags;
    }

    public function setWheres(array $wheres): void
    {
        $this->wheres = $wheres;
    }

    public function getWheres(): array
    {
        return $this->constWheres + $this->wheres;
    }

    public function toArray(): array
    {
        return [
            'with' => $this->getWithArray(),
            'fields' => $this->fields,
            'exclude' => $this->exclude,
            'where' => $this->wheres,
            'flags' => $this->flags,
            'required' => $this->isRequired,
            'ret_empty' => $this->isReturnEmpty
        ];
    }
}
