<?php

namespace Jam\Models\Traits\Internal;

use Jam\Models\Model;
use Jam\Models\ModelAbstract;
use Jam\Models\Relation;
use Jam\Models\Utils\ArrayHelper;
use stdClass;

trait Convertable
{
    /**
     * Возвращает информацию об иерархии отношений, необходимых для конверации в объект
     * @param ModelAbstract|array|null $model
     * @param array<string, mixed> $classChilds
     * @return array<string, array{type: string, childs?: array<string, mixed>}> Relation hierarchy info
     */
    public function getRelationHierarchy($model = null, $classChilds = [])
    {
        if (!$model) {
            $model = $this;
        } elseif ($model instanceof \ArrayAccess && isset($model[0])) {
            $model = $model[0];
        } else {
            return [];
        }
        $ret = [];
        $relations = $model->getAllRelations();

        $savedWith = $model->with;
        $model->with = [];
        $modelArray = $model->toArray();
        $model->with = $savedWith;

        /** @var Relation $relation */
        foreach ($relations as $relation) {
            $alias = $relation->alias();
            $class = $relation->getClass($modelArray);
            $ret[$alias] = ['type' => $relation->type()];
            if (isset($model->{$alias}) && !$relation->isTypeBelongs()) {
                if (empty($classChilds[$class])) {
                    $classChilds[$class] = 'busy';
                    $classChilds[$class] = $this->getRelationHierarchy($model->{$alias}, $classChilds);
                }
                if (!empty($classChilds[$class]) && $classChilds[$class] !== 'busy') {
                    $ret[$alias]['childs'] = $classChilds[$class];
                }
            }
        }
        return $ret;
    }

    /**
     * Инициализирует модель из массива
     * @param array $data Массив с данными
     * @param bool $silence Тихое конвертирование. Если установлен, свойства, не прошедшие валидацию, не будут записаны
     *                      Валидация проверяется возникновением исключения (см. trait DynamicProps::__set)
     * @return Model
     */
    public function fromArray(array $data, bool $silence = false)
    {
        $this->jamGuardSleep = true;
        $convertableHelper = new ConvertableHelperFromArray($this, $silence);
        $convertableHelper->convert($data);
        $this->jamGuardSleep = false;
        return $this;
    }

    public function fromObject(stdClass $obj, $silence = false)
    {
        return $this->fromArray(ArrayHelper::convertFromObject($obj), $silence);
    }

    // См. trait Withable::with($classesExpression)
    public function toArray($flags = null, $fields = null, $with = null, $exclude = null, $cutFields = true, $debug = false): array
    {
        // Усыпляем охранника
        $this->jamGuardSleep = true;
        $convertableHelperToArray = new ConvertableHelperToArray($this, $flags, $fields, $with, $exclude, $cutFields);
        $ret = $convertableHelperToArray->convert($debug);
        $this->jamGuardSleep = false;
        return $ret;
    }

    public function toObject($flags = null, $fields = null, $with = null, $exclude = null, $cutFields = true, $debug = false)
    {
        $hierarchy = $this->getRelationHierarchy();
        return ArrayHelper::convertToObject($this->toArray($flags, $fields, $with, $exclude, $cutFields, $debug), true, false, $hierarchy, false);
    }
}
