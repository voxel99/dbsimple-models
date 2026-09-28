<?php

namespace Jam\Models;

use Jam\Models\Utils\ArrayHelper;

class OnRetrieved
{
    protected ModelAbstract $model;
    protected array $rows;
    private array $rowClassesRelation = [];

    private int $countIterations = 0;
    private int $countHits = 0;
    private int $countMisses = 0;

    public function __construct(ModelAbstract $model, array $rows)
    {
        $this->model = $model;
        $this->rows = $rows;
    }

    private function getRowClasses(Relation $R): array
    {
        $rowClasses = [];
        if ($R->isClassInstanceOfClosure()) {
            // Класс может быть задан Closure и зависеть от конкретной записи,
            // например, UploadImage или UploadVideo для PostAttach.type
            foreach ($this->rows as $k => $row) {
                $rowClasses[$k] = $R->getClass($row);
            }
        } else {
            $class = $R->getClass();
            foreach ($this->rows as $k => $row) {
                $rowClasses[$k] = $class;
            }
        }
        return $rowClasses;
    }

    public function getRowKClass($k, Relation $R)
    {
        $alias = $R->alias();
        if (empty($this->rowClassesRelation[$alias])) {
            $this->rowClassesRelation[$alias] = $this->getRowClasses($R);
        }
        return $this->rowClassesRelation[$alias][$k];
    }

    private function modelClassname()
    {
        return $this->model instanceof ModelList
            ? $this->model->getClass()
            : get_class($this->model);
    }

    private function getRelationClasses(Relation $R): array
    {
        $classes = [];
        if ($R->isClassInstanceOfClosure()) {
            foreach ($this->rows as $row) {
                $class = $R->getClass($row);
                if ($class) {
                    $classes[] = $class;
                }
            }
            $classes = array_unique($classes);
        } else {
            $class = $R->getClass();
            $classes = [$class];
        }
        return $classes;
    }

    private function getValuesByClass(Relation $R): array
    {
        $valuesByClass = [];
        $localKey = $R->localKey();
        foreach ($this->rows as $k => $row) {
            $class = $this->getRowKClass($k, $R);
            if (!empty($row[$localKey]) && !empty($class)) {
                if (!isset($valuesByClass[$class])) {
                    $valuesByClass[$class] = (object) ['kArray' => [], 'values' => []];
                }
                $valuesByClass[$class]->kArray[] = $k;
                $valuesByClass[$class]->values[] = $row[$localKey];
            }
        }
        foreach ($valuesByClass as $values) {
            $values->values = array_unique($values->values);
        }
        return $valuesByClass;
    }

    private function getPivotList(Relation $R, array $values): ModelList
    {
        $pivotLocalKey = $R->pivotLocalKey();
        if (!$pivotLocalKey) {
            throw new ModelException(sprintf(
                "Empty pivot local key in model '%s', relation '%s'",
                $this->modelClassname(),
                $R->alias()
            ));
        }
        $pivot = $R->getPivot();
        $this->model->inheritModel($pivot);
        return $pivot->where([$pivotLocalKey => array_values($values)])->collection();
    }

    private function getPivotValues(Relation $R, ModelList $pivotList): array
    {
        $pivotOtherKey = $R->pivotOtherKey();
        if (!$pivotOtherKey) {
            throw new ModelException(sprintf(
                "Empty pivot other key in model '%s', relation '%s'",
                $this->modelClassname(),
                $R->alias()
            ));
        }
        $pivotValues = [];
        foreach ($pivotList as $item) {
            $pivotValues[] = $item->$pivotOtherKey;
        }
        return array_unique($pivotValues);
    }

    /**
     * Собирает условие связи. Условия [..] из with() объединяются через OR.
     *
     * this(field) подставляет значение поля родительской строки. Условие строится для каждой
     * родительской строки отдельно и привязывается к ней через ключ связи:
     *   (post_id = 1 AND (user_id <> 1)) OR (post_id = 3 AND (user_id <> 2))
     * Без привязки условия разных родителей смешивались и фильтр не работал.
     *
     * @param array<int, string> $wheres
     * @param array<int, int|string> $kArray Индексы родительских строк, относящихся к этому классу
     */
    private function getWhereString(Relation $R, array $wheres, array $kArray, Model $relationModel): string
    {
        $whereStr = count($wheres) > 1 ? "(" . implode(") OR (", $wheres) . ")" : $wheres[0];
        if (!preg_match_all('#(?<![\w])this\((\w+)\)#', $whereStr, $m)) {
            return $whereStr;
        }

        $db = $relationModel->getDb();
        // Для many-to-many связь идёт через pivot, привязать условие к ключу нельзя
        $bindToParent = !$R->getPivot();
        $newWhere = [];
        foreach ($kArray as $k) {
            $row = $this->rows[$k];
            $replace = [];
            foreach ($m[1] as $thisProp) {
                if (!array_key_exists($thisProp, $row)) {
                    throw new ModelException(sprintf(
                        "Property %s not found in model %s",
                        $thisProp,
                        $this->modelClassname()
                    ));
                }
                $replace['this(' . $thisProp . ')'] = $row[$thisProp] === null ? 'NULL' : $db->escape($row[$thisProp]);
            }
            $condition = strtr($whereStr, $replace);
            $newWhere[] = $bindToParent
                ? sprintf('%s = %s AND (%s)', Model::dbEsc($R->otherKey()), $db->escape($row[$R->localKey()]), $condition)
                : $condition;
        }

        return $newWhere ? "(" . implode(") OR (", array_unique($newWhere)) . ")" : "";
    }

    /**
     * @param object{kArray: array<int, int|string>, values: array<int, mixed>} $values
     * @param array<int, string>|null $fields Итоговый список полей связанной модели (выходной параметр)
     */
    private function createRelationModel(Relation $R, string $class, object $values, &$fields): Model
    {
        $otherKey = $R->otherKey();
        /** @var Model $RelationModel */
        $RelationModel = new $class();
        $this->model->inheritModel($RelationModel);
        $RelationModel->where([$otherKey => array_values($values->values)]);
        $with = $R->getWithArray();
        $fields = $R->getFields();
        $exclude = $R->getExclude();
        $wheres = $R->getWheres();

        if (count($with) > 0) {
            $RelationModel->with($with);
        }

        if ($fields) {
            $fields = array_unique(array_merge($fields, $RelationModel->getLocalKeys(), [$otherKey]));
            $RelationModel->cutFields($fields, true);
        }

        if ($wheres) {
            $RelationModel->where($this->getWhereString($R, $wheres, $values->kArray, $RelationModel));
        }

        if ($exclude) {
            $RelationModel->excludeFields($exclude);
        }
        return $RelationModel;
    }

    private function walkRelationCollection(
        array $kArray,
        Relation $R,
        array $relationCollection,
        string $class,
        callable $callback
    ): void {
        // Например, item=tag(id=1, name=...)
        foreach ($kArray as $k) {
            $row = $this->rows[$k];
            $rowClass = $this->getRowKClass($k, $R);
            if ($rowClass !== $class) {
                continue;
            }
            foreach ($relationCollection as $item) {
                if ($callback($k, $row, $item)) {
                    break;
                }
            }
        }
    }

    private function fillRowsPivot(
        array &$returnRows,
        array $kArray,
        Relation $R,
        Model $RelationModel,
        array $relationCollection,
        ModelList $pivotList,
        string $class,
        $fields
    ): void {
        $otherKey = $R->otherKey();
        $localKey = $R->localKey();
        $alias = $R->alias();
        $pivotOtherKey = $R->pivotOtherKey();
        $pivotLocalKey = $R->pivotLocalKey();

        $pivotArray = [];
        foreach ($pivotList as $pivotItem) {
            $pivotArray[$pivotItem->{$pivotOtherKey}][$pivotItem->{$pivotLocalKey}] = $pivotItem;
        }

        // $debugStr = "fillRowsPivot " . microtime(true);
        // debug()->simpleStart($debugStr);
        $this->walkRelationCollection($kArray, $R, $relationCollection, $class, function (
            int $k,
            array $row,
            array $item
        ) use (
            &$returnRows,
            $pivotArray,
            $otherKey,
            $localKey,
            $pivotOtherKey,
            $pivotLocalKey,
            $RelationModel,
            $alias,
            $fields
        ) {
            foreach ($pivotArray as $pivotOtherKey => $pivotItems) {
                if ($item[$otherKey] != $pivotOtherKey) {
                    continue;
                }
                foreach ($pivotItems as $pivotLocalKey => $pivotItem) {
                    $this->countIterations++;
                    if ($row[$localKey] == $pivotLocalKey) {
                        $relationItem = $RelationModel->cutItem($item, $fields);
                        $relationItem['@pivot'] = $pivotItem;
                        $returnRows[$k][$alias][] = $relationItem;
                        $this->countHits++;
                        break;
                    } else {
                        $this->countMisses++;
                    }
                }
            }
        });
        // debug()->simpleEnd($debugStr);
    }

    private function fillRows(
        array &$returnRows,
        array $kArray,
        Relation $R,
        Model $RelationModel,
        array $relationCollection,
        string $class,
        $fields
    ): void {
        $otherKey = $R->otherKey();
        $localKey = $R->localKey();
        $alias = $R->alias();

        $this->walkRelationCollection($kArray, $R, $relationCollection, $class, function (
            int $k,
            array $row,
            array $item
        ) use (
            &$returnRows,
            $R,
            $otherKey,
            $localKey,
            $RelationModel,
            $alias,
            $fields
        ) {
            $this->countIterations++;
            // Нельзя делать строгое === из-за приведения типов в toArray (см getCast в trait DynamicProps)
            if ($item[$otherKey] == $row[$localKey]) {
                $this->countHits++;
                if ($R->isTypeMany()) {
                    $returnRows[$k][$alias][] = $RelationModel->cutItem($item, $fields);
                } else {
                    $returnRows[$k][$alias] = $RelationModel->cutItem($item, $fields);
                    return true;
                }
            } else {
                $this->countMisses++;
            }

            return false;
        });
    }

    public function keyBy(array &$returnRows, array $kArray, Model $RelationModel, string $keyBy, string $alias): void
    {
        $strings = [];
        if ($RelationModel instanceof StringableInterface) {
            $strings = $RelationModel::strings();
        }
        foreach ($kArray as $k) {
            if (!empty($returnRows[$k][$alias]) && is_array($returnRows[$k][$alias])) {
                $returnRows[$k][$alias] = ArrayHelper::keyBy($returnRows[$k][$alias], $keyBy, $strings);
            }
        }
    }

    public function fillTitles(array &$returnRows, array $kArray, Model $RelationModel, $titles, string $alias): void
    {
        $allTitles = $RelationModel->__getAllTitles($titles);
        foreach ($kArray as $k) {
            $row = $this->rows[$k];
            if (!empty($returnRows[$k][$alias]) && is_array($returnRows[$k][$alias])) {
                $returnRows[$k][$alias][$RelationModel::TITLES_FIELD] = [];
                foreach ($allTitles as $f => $allTitle) {
                    $returnRows[$k][$alias][$RelationModel::TITLES_FIELD][$f] = $allTitle[$row[$f]];
                }
            }
        }
    }

    private function dispatchValues(
        string $class,
        Relation $R,
        object $values,
        ?ModelList $pivotList,
        array &$returnRows
    ): void {
        $alias = $R->alias();
        $RelationModel = $this->createRelationModel($R, $class, $values, $fields);

        $flags = $R->getFlags();
        $offset = $this->model->getFlag($flags, Model::FLAG_OFFSET);
        $limit = $this->model->getFlag($flags, Model::FLAG_LIMIT);
        $orderBy = $this->model->getFlag($flags, Model::FLAG_ORDER);
        $withTrashed =  $this->model->getFlag($flags, Model::FLAG_WITH_TRASHED);

        $keyBy = $this->model->getFlag($flags, Model::FLAG_KEYBY);
        $titles = $this->model->getFlag($flags, Model::FLAG_TITLES);

        if ($withTrashed && method_exists($RelationModel, 'withTrashed')) {
            $RelationModel->withTrashed();
        }

        $relationCollection = $RelationModel->collectionRaw(null, $limit, $offset, $orderBy);

        if ($pivotList) {
            $this->fillRowsPivot(
                $returnRows,
                $values->kArray,
                $R,
                $RelationModel,
                $relationCollection,
                $pivotList,
                $class,
                $fields
            );
        } else {
            $this->fillRows($returnRows, $values->kArray, $R, $RelationModel, $relationCollection, $class, $fields);
        }
        if ($keyBy && $alias) {
            $this->keyBy($returnRows, $values->kArray, $RelationModel, $keyBy, $alias);
        }
        if ($titles && $alias) {
            $this->fillTitles($returnRows, $values->kArray, $RelationModel, $titles, $alias);
        }
    }

    /**
     * @throws ModelException
     */
    private function dispatchValuesByClass(Relation $R, array $valuesByClass, array &$returnRows): void
    {
        foreach ($valuesByClass as $class => $values) {
            $pivotList = null;
            if ($R->getPivot()) {
                $pivotList = $this->getPivotList($R, $values->values);
                $R->setPivotModelList($pivotList);
                $values->values = $this->getPivotValues($R, $pivotList);
            }

            if ($values->values) {
                $this->dispatchValues($class, $R, $values, $pivotList, $returnRows);
            }
        }
    }

    /**
     * @throws ModelException
     */
    private function dispatchRelation(Relation $R, array &$returnRows): void
    {
        $classes = $this->getRelationClasses($R);
        // Связанных классов может не быть, например, для текстовых атачей (upload_id=0)
        if (!$classes) {
            return;
        }
        $this->dispatchValuesByClass($R, $this->getValuesByClass($R), $returnRows);
    }

    /**
     * Обработать строки, добавив связанные подстроки
     *
     * @return array<int, array<string, mixed>> Array of rows with related data
     * @throws ModelException
     */
    public function dispatch(): array
    {
        if (empty($this->rows[0])) {
            return $this->rows;
        }
        $returnRows = $this->rows;
        $with = $this->model->getWithArray();
        foreach ($with as $R) {
            if ($R != Model::EXPLICIT) {
                $this->dispatchRelation($R, $returnRows);
            }
        }
        return $returnRows;
    }
}
