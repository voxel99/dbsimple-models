<?php

namespace Jam\Models\Traits\Internal;

use Jam\Models\Cast\CastClosure;
use Jam\Models\Model;
use Jam\Models\ModelAbstract;
use Jam\Models\ModelException;
use Jam\Models\ModelList;
use Jam\Models\Relation;
use Jam\Models\ScalarableInterface;
use Jam\Models\StringableInterface;
use Jam\Models\Utils\ArrayHelper;

class ConvertableHelperToArray
{
    public static bool $guardCheckState = false;

    /** @var Model */
    protected $model;

    /** @var array Флаги преобразования */
    protected array $flags;

    /** @var array Поля результирующего массива */
    protected array $fields;

    protected array $explicit = [];

    /** @var array Исходные поля (без вычислимых, скрытых и пр) */
    protected array $srcFields;

    /** @var array */
    protected ?array $with;

    /** @var array */
    protected ?array $exclude;

    /** @var bool */
    protected bool $cutFields;

    /** @var array */
    protected array $relations;

    protected bool $debug = false;

    public function __construct(
        Model $model,
        $flags = null,
        $fields = null,
        ?array $with = null,
        ?array $exclude = null,
        bool $cutFields = false
    ) {
        $this->model = $model;
        $this->with = array_merge($with ?: [], $model->getWithArray(true));
        $this->setFlags($flags);
        $this->setFields($fields);
        $this->exclude = $exclude;
        $this->cutFields = $cutFields;
        $this->relations = $model->getAllRelations();
        $explicit = $model->getExplicit();
        foreach ($this->with as $alias => $R) {
            if (in_array($alias, $explicit)) {
                $this->explicit[] = $alias;
            }
        }
    }

    /**
     * Установить флаги конвертации
     * @param null|array|string $flags
     * @throws \Exception
     */
    public function setFlags($flags)
    {
        $modelObject = $this->model;
        if (is_null($flags)) {
            $flags = [Model::FLAG_DEPENDENCIES => true];
        }
        if (!is_array($flags)) {
            $flags = $modelObject->parseFlags($flags);
        }
        $this->flags = $flags;
    }

    /**
     * Устаносить поля результирующего массива
     * @param array|null|string $fields
     */
    public function setFields($fields)
    {
        $this->srcFields = [];
        if (!$fields) {
            $fields = $this->model->getFields();
        } else {
            if (!is_array($fields)) {
                $fields = ArrayHelper::stringCommasToArray($fields);
            }
            $this->srcFields = $fields;
            $computableFlag = $this->getFlag(Model::FLAG_COMPUTABLE);
            $computableFields = is_bool($computableFlag) ? ($computableFlag ? $this->model->getComputed() : []) : $this->getFlagFields($computableFlag);
            $hiddenFlag = $this->getFlag(Model::FLAG_HIDDEN);
            $hiddenFields = is_bool($hiddenFlag) ? ($hiddenFlag ? $this->model->getHidden() : []) : $this->getFlagFields($hiddenFlag);
            $extraFlag = $this->getFlag(Model::FLAG_EXTRA);
            $extraFields = is_bool($extraFlag) ? ($extraFlag ? $this->model->getExtra() : []) : $this->getFlagFields($extraFlag);
            $fields = array_unique(array_merge($fields, $computableFields ?: [], $hiddenFields ?: [], $extraFields ?: []));
        }
        $this->fields = $fields;
    }

    private function getFlagFields($flagValue)
    {
        $flagFields = $flagValue;
        if (is_string($flagFields)) {
            $flagFields = ArrayHelper::stringCommasToArray($flagFields);
        }
        return is_array($flagFields) ? $flagFields : null;
    }

    private function getFlag($flagName)
    {
        return $this->model->getFlag($this->flags, $flagName);
    }

    private function checkFlagField($flagValue, $field)
    {
        if (!is_bool($flagValue)) {
            $flagValue = $this->getFlagFields($flagValue);
        }
        if (is_array($flagValue)) {
            $flagValue = in_array($field, $flagValue);
        }
        return (bool)$flagValue;
    }

    private function getBooleanFlags(array $flags)
    {
        $ret = [];
        foreach ($flags as $k => $v) {
            if (is_bool($v)) {
                $ret[$k] = $v;
            }
        }
        return $ret;
    }

    private function getDerivedBooleanFlags($flags)
    {
        $flags = $this->getBooleanFlags($flags);
        $ret = [];
        foreach ($flags as $k => $v) {
            if (!in_array($k, [Model::FLAG_COMPUTABLE])) {
                $ret[$k] = $v;
            }
        }
        return $ret;
    }

    private function getTraceFields()
    {
        $traceFields = array_unique(array_merge(
            array_keys($this->model->getDataDB()),
            array_keys($this->model->getDataEX()),
            $this->model->getRelationAliases(),
            $this->model->getComputed(),
            $this->model->getHidden(),
            $this->model->getExtra(),
            $this->model->getUpdatable(),
            $this->explicit
        ));

        if ($this->exclude) {
            $traceFields = array_diff($traceFields, (array)$this->exclude);
        }

        if ($this->getFlag(Model::FLAG_INTERNAL_DATABASE)) {
            $traceFields = array_map(function ($item) {
                return strpos($item, ":") ? $item : $item . ":" . CastClosure::CAST_DB;
            }, $traceFields);
        }

        foreach ([Model::PIVOT_FIELD, Model::TITLES_FIELD, Model::IDENTIFIERS_FIELD] as $f) {
            $pivotIndex = array_search($f, $traceFields);
            if ($pivotIndex === false) {
                $traceFields[] = $f;
            }
        }
        return $traceFields;
    }

    private function getPivotValue($value, &$isWriteValue): array
    {
        $newValue = [];
        $withPivot = $this->getFlag(Model::FLAG_PIVOT); // p
        if (!$withPivot) {
            $isWriteValue = false;
        } else {
            if (is_string($withPivot)) {
                $pivotFields = ArrayHelper::stringCommasToArray($withPivot);
                foreach ($pivotFields as $pivotField) {
                    if (!isset($value->$pivotField)) {
                        throw new ModelException(sprintf(
                            "Pivot field not set: %s in '%s', class %s",
                            $pivotField,
                            $withPivot,
                            get_class($this->model)
                        ));
                    }
                    $newValue[$pivotField] = $value->$pivotField;
                }
            } else {
                $newValue = $value->toArray($this->flags);
                /*
                if (is_object($value)) {
                    $newValue = ArrayHelper::convertFromObject($value);
                } else if (!is_array($value)) {

                } else {
                    $newValue = $value;
                }
                */
            }
            $newValue = $this->processAfterConvert($newValue, $value);
        }
        return $newValue;
    }

    private function getFieldNormalizeName($traceField)
    {
        $field = $traceField;
        if (strpos($traceField, ":")) {
            list($field,) = ArrayHelper::stringCommasToArray($traceField, ":");
        }
        return $field;
    }

    private function getValueByRelation(Relation $R)
    {
        if ($R->isTypeMany()) {
            $value = [];
        } else {
            $value = (object)[];
        }
        return $value;
    }

    private function getRelationByNulledValue($field, $value): ?Relation
    {
        $withField = $this->with[$field] ?? null;
        if (is_null($value) && !empty($withField['ret_empty']) && !empty($this->relations[$field])) {
            return $this->relations[$field];
        }
        return null;
    }

    private function checkHidden($field, $withHidden)
    {
        // hidden-поля показываем, только если задан флаг и поле есть в перечислении
        return $withHidden && in_array($field, $this->fields) && $this->checkFlagField($withHidden, $field);
    }

    private function getValue($traceField, $field, &$isWriteValue)
    {
        $withExtra = $this->getFlag(Model::FLAG_EXTRA); // x
        $withHidden = $this->getFlag(Model::FLAG_HIDDEN); // h
        $withUpdatable = $this->getFlag(Model::FLAG_UPDATABLE); // u
        $withComputable = $this->getFlag(Model::FLAG_COMPUTABLE); // c
        $withDependences = $this->getFlag(Model::FLAG_DEPENDENCIES); // d
        $isExplicit = in_array($field, $this->explicit);

        $value = isset($this->model->{$traceField}) ? $this->model->{$traceField} : null;

        $R = $this->getRelationByNulledValue($field, $value);
        if ($R) {
            $value = $this->getValueByRelation($R);
            $isWriteValue = true;
        } elseif (($value instanceof Model) || ($value instanceof ModelList)) {
            $isWriteValue = $this->checkFlagField($withDependences, $field);
        } elseif ($isExplicit || ($withComputable && in_array($field, $this->model->getComputed()))) {
            if (!in_array($field, $this->model->getUpdatable()) || !isset($value)) {
                try {
                    $value = $this->model->$field();
                } catch (\Throwable $e) {
                    throw new ModelException(sprintf("Error in calculate explicit field: `%s`", $field), 0, $e);
                    // $value = null;
                }
            }
            $isWriteValue = !is_null($value) && ($isExplicit || $this->checkFlagField($withComputable, $field));
        } elseif ($withUpdatable && in_array($field, $this->model->getUpdatable())) {
            $value = $this->model->$field();
            $isWriteValue = $this->checkFlagField($withUpdatable, $field);
        } elseif (in_array($field, $this->model->getHidden())) {
            $isWriteValue = !is_null($value) && $this->checkHidden($field, $withHidden);
        } elseif (in_array($field, $this->model->getExtra())) {
            // extra поля показываем, если задан флаг
            if ($withExtra) {
                $isWriteValue = $this->checkFlagField($withExtra, $field);
            }
        } elseif (!is_null($value) || ($this->getFlag(Model::FLAG_EMPTY) && $this->model->isField($field))) {
            // Флаг e: null-значения обычных полей тоже попадают в результат
            $isWriteValue = true;
        }
        if (is_null($value) && (!$this->getFlag(Model::FLAG_EMPTY) || in_array($field, $this->model->getExtra()))) {
            $isWriteValue = false;
        }
        return $value;
    }

    private function getModelValue($field, ModelAbstract $value)
    {
        $withField = $this->with[$field] ?? null;
        $flagsRelated = $withField['flags'] ?? null;
        $fieldsRelated = $withField['fields'] ?? null;
        $withRelated = $withField['with'] ?? null;
        $excludeRelated = $withField['exclude'] ?? null;

        if (!$flagsRelated) {
            $flagsRelated = $this->getDerivedBooleanFlags($this->flags);
        } else {
            $flagsRelated = array_merge($flagsRelated, $this->getDerivedBooleanFlags($this->flags));
        }

        if (
            !empty($flagsRelated[Model::FLAG_SCALAR]) &&
            $value instanceof ScalarableInterface
        ) {
            /** @var class-string<ScalarableInterface> $scalarableClass */
            $scalarableClass = $value::class;
            return $scalarableClass::toScalar($value);
        }

        return $value->toArray($flagsRelated, $fieldsRelated, $withRelated, $excludeRelated, false);
    }

    private function processTraceField($traceField, &$ret)
    {
        $field = $this->getFieldNormalizeName($traceField);
        $isUpdatable = $this->getFlag(Model::FLAG_UPDATABLE) && in_array($field, $this->model->getUpdatable());
        $failComputed = !$this->getFlag(Model::FLAG_COMPUTABLE) && in_array($field, $this->model->getComputed());
        $failHidden = !$this->getFlag(Model::FLAG_HIDDEN) && in_array($field, $this->model->getHidden());
        $failExtra =  !$this->getFlag(Model::FLAG_EXTRA) && in_array($field, $this->model->getExtra());

        if (!$this->getFlag(Model::FLAG_DEPENDENCIES) && in_array($field, $this->model->getRelationAliases())) {
            return;
        }
        if (!$isUpdatable && ($failComputed || $failHidden || $failExtra)) {
            return;
        }
        $isWriteValue = false;
        $value = $this->getValue($traceField, $field, $isWriteValue);

        if ($isWriteValue) {
            if ($field === Model::PIVOT_FIELD) {
                $value = $this->getPivotValue($value, $isWriteValue);
            } elseif ($value instanceof ModelAbstract) {
                $value = $this->getModelValue($field, $value);
            }
        }
        if ($isWriteValue) {
            $ret[$field] = $value;
        }
    }

    private function processStringable(array $ret, Model $modelObject)
    {
        if ($modelObject instanceof StringableInterface) {
            $strings = $modelObject::strings();
            foreach ($strings as $type => $values) {
                if (isset($ret[$type])) {
                    $ret[$type] = $modelObject::toString($type, $ret[$type]);
                }
            }
        }
        return $ret;
    }

    private function processSkipNoStructure(array &$ret, $k)
    {
        foreach ((array)$ret[$k] as $j => $v) {
            if ($v === Model::VALUE_NO) {
                if (is_array($ret[$k])) {
                    unset($ret[$k][$j]);
                } else {
                    unset($ret[$k]->$j);
                }
            }
        }
    }

    private function processSkipNo(array &$ret, $k)
    {
        if (is_string($ret[$k])) {
            if ($ret[$k] === Model::VALUE_NO) {
                unset($ret[$k]);
            }
        } elseif (is_array($ret[$k]) || is_object($ret[$k])) {
            $this->processSkipNoStructure($ret, $k);
        } else {
            // TODO Check 'no' in other structures
        }
    }

    private function processCast(array $ret, Model $modelObject, $debug = false)
    {
        $cast = $modelObject->getCast($debug);
        foreach ($cast as $k => $v) {
            if (!isset($ret[$k]) || is_null($this->model->{$k})) {
                continue;
            }
            $closureClass = CastClosure::fromCast($cast[$k]);
            if ($closureClass) {
                $ret[$k] = $closureClass->stringValue($this->model->{$k});
                if ($this->getFlag(Model::FLAG_SKIP_NO)) {
                    $this->processSkipNo($ret, $k);
                }
            }
        }
        return $ret;
    }

    public function processAfterConvert(array $ret, Model $modelObject, $debug = false)
    {
        // Приводим числовые идентификаторы к строковым (если задано преобразование и действие)
        if ($this->getFlag(Model::FLAG_STRINGABLE)) {
            $ret = $this->processStringable($ret, $modelObject);
        }
        // Приводим типы
        if (!$this->getFlag(Model::FLAG_INTERNAL_DATABASE)) {
            $ret = $this->processCast($ret, $modelObject, $debug);
        }
        return $ret;
    }

    public function convert($debug = false)
    {
        $this->debug = $debug;
        $modelObject = $this->model;
        if ($this->getFlag(Model::FLAG_SCALAR) && ($this->model instanceof ScalarableInterface)) {
            /** @var class-string<ScalarableInterface> $scalarableClass */
            $scalarableClass = $modelObject::class;
            $ret = $scalarableClass::toScalar($this->model);
        } else {
            $ret = [];
            // Получаем из флагов правила конвертации
            $traceFields = $this->getTraceFields();
            foreach ($traceFields as $traceField) {
                $this->processTraceField($traceField, $ret);
            }
            $ret = $this->processAfterConvert($ret, $this->model, $debug);
        }
        if ($this->getFlag(Model::FLAG_TITLES)) {
            $ret[Model::TITLES_FIELD] = $this->model->__getTitles($this->srcFields);
        }
        if ($this->getFlag(Model::FLAG_IDENTIFIERS)) {
            $ret[Model::IDENTIFIERS_FIELD] = $this->model->__getIdentifiers($this->srcFields);
        }

        if ($this->srcFields && $this->cutFields) {
            $returnKeys = array_flip($this->srcFields);
            // + check? $this->getFlag(Model::FLAG_PIVOT)
            foreach ([Model::PIVOT_FIELD, Model::TITLES_FIELD, Model::IDENTIFIERS_FIELD] as $f) {
                $returnKeys[$f] = true;
            }
            if ($this->getFlag(Model::FLAG_DEPENDENCIES)) {
                $relationAliases = $modelObject->getRelationAliases();
                if (!empty($relationAliases)) {
                    foreach ($relationAliases as $relationAlias) {
                        $returnKeys[$relationAlias] = true;
                    }
                }
            }
            $ret = array_intersect_key($ret, $returnKeys);
        }
        return $ret;
    }
}
