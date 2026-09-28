<?php

namespace Jam\Models\Traits\Crud;

use Exception;
use Jam\Models\DummyModel;
use Jam\Models\Model;
use Jam\Models\ModelAbstract;
use Jam\Models\ModelException;
use Jam\Models\ModelList;
use Jam\Models\StringableInterface;
use Jam\Models\Utils\ArrayHelper;
use Jam\DbSimple\SubQuery;

trait Getable
{
    /**
     * Поля для аыборки, если не заданы явно в методы выборки collection, first
     * @var array
     */
    protected $outFields = [];
    /**
     * Условия выборок вместе с параметрами (сцепляются AND)
     * @var array
     */
    protected $where = [];

    /**
     * Joins
     * @var array
     */
    protected $joins = [];

    /**
     * Left joins
     * @var array
     */
    protected $leftJoins = [];

    /**
     * Right joins
     * @var array
     */
    protected $rightJoins = [];

    /**
     * Group by operand
     * @var string
     */
    protected string $groupBy = '';

    /**
     * LOCK IN SHARE MODE | FOR UPDATE
     * @var string
     */
    protected string $lock = '';

    /**
     * COUNT(), AVG(), MIN(), MAX()
     * @var string
     */
    protected string $aggregateFunc = '';

    protected ?string $orderBy = null;
    protected ?int $limitValue = null;
    protected ?int $offsetValue = null;

    /**
     * Очищает переменные цепочки
     * @return $this
     */
    public function clear($aggregateOnly = false)
    {
        $this->aggregateFunc = '';
        if (!$aggregateOnly) {
            $this->where = [];
            $this->joins = [];
            $this->leftJoins = [];
            $this->rightJoins = [];
            $this->lock = '';
            $this->orderBy = null;
            $this->limitValue = null;
            $this->offsetValue = null;
        }
        return $this;
    }

    public function order(string $orderBy): static
    {
        $this->orderBy = $orderBy;
        return $this;
    }

    public function orderBy(string $orderBy): static
    {
        return $this->order($orderBy);
    }

    public function limit(?int $limit): static
    {
        $this->limitValue = $limit;
        return $this;
    }

    public function offset(?int $offset): static
    {
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * @return array{0: string|array|null, 1: int|null, 2: int|null, 3: mixed}
     */
    private function resolveCollectionArgs($fields = null, $limit = null, $offset = null, $orderby = null): array
    {
        if (is_int($fields) && ($limit === null || is_int($limit)) && $offset === null && $orderby === null) {
            $offset = $limit;
            $limit = $fields;
            $fields = null;
        }

        return [
            $fields,
            $limit ?? $this->limitValue,
            $offset ?? $this->offsetValue,
            $orderby ?? $this->orderBy,
        ];
    }

    public function lockInShareMode(): static
    {
        $this->lock = ' LOCK IN SHARE MODE';
        return $this;
    }

    public function lockForUpdate(): static
    {
        $this->lock = ' FOR UPDATE';
        return $this;
    }

    public function setOutFields(string|array $fields): static
    {
        $this->outFields = is_string($fields) ? ArrayHelper::stringCommasToArray($fields) : $fields;
        return $this;
    }

    /**
     * Задаёт условие выборки
     *
     * У функции может быть множество параметров, например
     * where('id=?d AND dt>NOW()-INTERVAL ?d DAY', $id, $period)
     *
     * @param string $condition Условие выборки
     * @return static
     */
    public function where(string|null $condition): static
    {
        if ($condition) {
            $this->where[] = func_get_args();
        }
        return $this;
    }

    private function addJoins(array $expression, $field = "joins"): static
    {
        if ($expression) {
            $this->{$field} = array_merge($this->{$field}, $expression);
        }
        return $this;
    }

    public function join(array|string|null $expression): static
    {
        if ($expression) {
            if (!is_array($expression)) {
                $expression = [func_get_args()];
            }
            return $this->addJoins($expression);
        }
        return $this;
    }

    public function leftJoin(array|string|null $expression): static
    {
        if ($expression) {
            if (!is_array($expression)) {
                $expression = [func_get_args()];
            }
            return $this->addJoins($expression, "leftJoins");
        }
        return $this;
    }

    public function rightJoin(array|string|null $expression): static
    {
        if ($expression) {
            if (!is_array($expression)) {
                $expression = [func_get_args()];
            }
            return $this->addJoins($expression, "rightJoins");
        }
        return $this;
    }

    public function groupBy(string $groupBy)
    {
        $this->groupBy = $groupBy;
    }

    public function getWhere()
    {
        return $this->where;
    }

    public function setWhere(array $where)
    {
        $this->where = $where;
    }

    private function getWhereConditions()
    {
        $where = [];
        if ($this->where) {
            foreach ($this->where as $cond) {
                $where[] = call_user_func_array([$this->db, 'subquery'], $cond)->get();
            }
        }
        return $where;
    }

    /**
     * Получить фильтр модели в виде строки WHERE SQL-запроса
     * @return string
     */
    public function getWhereString(): string
    {
        $where = $this->getWhereConditions();
        $whereExpr = count($where) > 1
            ? "(" . implode(") AND (", array_unique($where)) . ")"
            : (
            empty($where)
                ? ''
                : $where[0]
            );
        return $whereExpr;
    }

    public function getWhereSubstitute()
    {
        $whereStr = $this->getWhereString();
        return $whereStr ? $this->db->subquery($whereStr) : DBSIMPLE_SKIP;
    }

    private function strSubQuery($expr)
    {
        $sub = is_object($expr) && get_class($expr) === SubQuery::class
            ? $expr
            : call_user_func_array([$this->db, 'subquery'], $expr);

        return $sub->get();
    }

    private function escapeField($field)
    {
        if (
            strpos($field, ' ') === false &&
            strpos($field, '.') === false &&
            strpos($field, '`') === false &&
            strpos($field, '*') === false
        ) {
            $field = '`' . $field . '`';
        }
        return $field;
    }

    private function appendSoftDeleteWhere(string $where, array $allFields): string
    {
        if (!in_array($this->columnDeleted, $allFields)) {
            return $where;
        }

        if (!empty($this->collectionWithDeleted) || strpos($where, $this->columnDeleted) !== false) {
            return $where;
        }

        return $where
            . ($where ? " AND" : "")
            . sprintf(
                " %sdeleted_at IS NULL",
                $this->alias ? $this->alias . "." : ""
            );
    }

    private function buildJoinsExpression(): string
    {
        $joins = [];
        $joinPrefixes = [
            'joins' => 'JOIN',
            'leftJoins' => 'LEFT JOIN',
            'rightJoins' => 'RIGTH JOIN'
        ];

        foreach (array_keys($joinPrefixes) as $joinType) {
            if (!$this->{$joinType}) {
                continue;
            }

            foreach ($this->{$joinType} as $join) {
                if (!$join) {
                    continue;
                }

                $joinString = $this->strSubQuery($join);
                if (!$joinString) {
                    continue;
                }

                $joins[] = $joinPrefixes[$joinType]
                    . ' '
                    . preg_replace('#^' . $joinPrefixes[$joinType] . '#i', '', $joinString);
            }
        }

        return implode("\n", $joins);
    }

    /**
     * @return array{0: string|object|null, 1: string}
     */
    private function resolveOrderByParts($orderby): array
    {
        $orderbyDesc = '';

        if (!$orderby) {
            return [$orderby, $orderbyDesc];
        }

        if (is_object($orderby) && !empty($orderby->key)) {
            $orderbyDesc = !empty($orderby->order) ? $orderby->order : '';
            return [$orderby->key, $orderbyDesc];
        }

        // Составная сортировка «num, id» / «updated_at DESC, id DESC»: раньше
        // резалась по первому пробелу → ORDER BY `num,` id (SQL-ошибка).
        if (strpos($orderby, ',') !== false && strpos($orderby, '(') === false) {
            return [$this->multiColumnOrderBy($orderby), ''];
        }

        if (strpos($orderby, ' ') !== false) {
            [$orderby, $orderbyDesc] = explode(' ', $orderby, 2);
        }

        return [$orderby, $orderbyDesc];
    }

    /** Уже экранированный список колонок с направлениями. */
    private function multiColumnOrderBy(string $orderby): string
    {
        $parts = [];
        foreach (explode(',', $orderby) as $part) {
            $tokens = preg_split('/\s+/', trim($part)) ?: [];
            if (!$tokens || $tokens[0] === '') {
                continue;
            }
            $direction = strtoupper($tokens[1] ?? '');
            $parts[] = static::dbEsc($tokens[0]) . (in_array($direction, ['ASC', 'DESC'], true) ? ' ' . $direction : '');
        }
        return implode(', ', $parts);
    }

    /**
     * @param array<int, string>|null $fields
     * @return array<int, string>
     */
    private function resolveSelectedFields(?array $fields): array
    {
        $fields ??= $this->outFields;
        if (!$fields) {
            return ['*'];
        }

        $hasAsterisk = false;
        foreach ($fields as $field) {
            if ($field === '*' || ($this->alias && $field === $this->alias . '.*')) {
                $hasAsterisk = true;
                break;
            }
        }

        if (!$hasAsterisk) {
            $with = $this->getWithArray();
            if (!empty($with)) {
                $keyFields = [];
                $alias = $this->getAlias();
                foreach ($with as $withRelation) {
                    if ($withRelation === self::EXPLICIT) {
                        continue;
                    }
                    $keyFields[] = ($alias ? $alias . '.' : '') . $withRelation->localKey;
                }
                $fields = array_unique(array_merge($fields, $keyFields));
            }
        }

        $selectedFields = [];
        foreach ($fields as $field) {
            $selectedFields[] = strpos($field, "*") !== false
                ? $field
                : $this->escapeField($field);
        }

        return $selectedFields;
    }

    private function normalizeSqlOperand(string $operand): string
    {
        return strpos($operand, '(') === false
            ? static::dbEsc($operand)
            : $operand;
    }

    /**
     * @return array{0: array<int, string>, 1: bool, 2: bool}
     */
    private function resolveDummyConditions(string $where): array
    {
        $whereLower = strtolower($where);
        $isOrCondition = strpos($whereLower, ' or ') !== false;
        $isAndCondition = strpos($whereLower, ' and ') !== false;

        if ($isOrCondition && $isAndCondition) {
            throw new ModelException('DummyModel can process only one type of conditions: AND or OR');
        }

        if ($isOrCondition) {
            return [explode(' or ', $whereLower), true, false];
        }

        if ($isAndCondition) {
            return [explode(' and ', $whereLower), false, true];
        }

        return [[$whereLower], false, false];
    }

    /**
     * @return array{0: string, 1: string|array<int, string>, 2: bool}
     */
    private function parseDummyCondition(string $condition, array $allFields, string $where): array
    {
        $isInCondition = str_contains($condition, ' in ');
        $isEqualsCondition = str_contains($condition, '=');

        if (!$isInCondition && !$isEqualsCondition) {
            throw new ModelException('DummyModel used equals only');
        }

        if ($isEqualsCondition) {
            [$key, $value] = explode('=', $condition);
        } else {
            [$key, $value] = explode(' in ', $condition);
        }

        $key = trim($key, '`\'" ' . ($isInCondition ? '(' : ''));

        foreach ([$key, $value] as $token) {
            foreach (['>', '<'] as $notAllowed) {
                if (strpos($token, $notAllowed) !== false) {
                    throw new ModelException(sprintf('DummyModel not allow condition %s', $where));
                }
            }
        }

        if (!in_array($key, $allFields)) {
            throw new ModelException(sprintf(
                'DummyModel %s havn`t field %s in expression %s',
                get_class($this),
                $key,
                $where
            ));
        }

        if ($isInCondition) {
            $value = trim($value, '()');
            $value = array_map(function ($singleValue) {
                return trim($singleValue, '`\'" ');
            }, ArrayHelper::stringCommasToArray($value));
        } else {
            $value = trim($value, '`\'" ');
        }

        return [$key, $value, $isInCondition];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string|array<int, string> $value
     * @return array<int, int>
     */
    private function findDummyMatchedIndexes(array $rows, string $key, string|array $value, bool $isInCondition): array
    {
        $matchedIndexes = [];

        foreach ($rows as $index => $item) {
            $matched = isset($item[$key]) && (
                (!$isInCondition && ($item[$key] == $value))
                || ($isInCondition && in_array($item[$key], $value))
            );

            if ($matched) {
                $matchedIndexes[] = $index;
            }
        }

        return $matchedIndexes;
    }

    /**
     * @param array<int, array<int, int>> $resultIndexes
     * @return array<int, int>
     */
    private function mergeDummyConditionIndexes(array $resultIndexes, bool $isAndCondition): array
    {
        $resultIndexes = array_values($resultIndexes);
        $indexes = $resultIndexes[0];

        for ($i = 1; $i < count($resultIndexes); $i++) {
            if ($isAndCondition) {
                $indexes = array_intersect($indexes, $resultIndexes[$i]);
            } else {
                $indexes = array_merge($indexes, $resultIndexes[$i]);
            }
        }

        return $indexes;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterDummyRows(array $rows, string $where, array $allFields): array
    {
        [$conditions, , $isAndCondition] = $this->resolveDummyConditions($where);
        $resultIndexes = [];

        foreach ($conditions as $conditionIndex => $condition) {
            [$key, $value, $isInCondition] = $this->parseDummyCondition($condition, $allFields, $where);
            $resultIndexes[$conditionIndex] = $this->findDummyMatchedIndexes($rows, $key, $value, $isInCondition);
        }

        if (empty($resultIndexes)) {
            return [];
        }

        $filteredRows = [];
        foreach ($this->mergeDummyConditionIndexes($resultIndexes, $isAndCondition) as $index) {
            $filteredRows[] = $rows[$index];
        }

        return $filteredRows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function orderDummyRows(array $rows, $orderby): array
    {
        if (!$orderby || count($rows) <= 1) {
            return $rows;
        }

        $orderbyDesc = '';
        if (strpos($orderby, ' ') !== false) {
            [$orderby, $orderbyDesc] = explode(' ', $orderby, 2);
        }
        $isReverse = strtolower($orderbyDesc) === 'desc';

        usort($rows, function ($a, $b) use ($orderby, $isReverse) {
            $left = $a[$orderby];
            $right = $b[$orderby];
            $result = is_numeric($left) && is_numeric($right)
                ? ($left <=> $right)
                : strcmp((string) $left, (string) $right);

            if ($isReverse) {
                $result *= -1;
            }

            return $result;
        });

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sliceDummyRows(array $rows, $limit, $offset): array
    {
        if (!$offset && !$limit) {
            return $rows;
        }

        return array_slice($rows, $offset ?? 0, $limit);
    }

    private function getDummyRows(DummyModel $dummyModel, ?array $fields, $limit, $offset, $orderby)
    {
        $where = $this->getWhereConditions();
        $rows = $dummyModel->dummyRows();
        if (!empty($where)) {
            $allFields = $this->getFields();
            foreach ($where as $w) {
                $rows = $this->filterDummyRows($rows, $w, $allFields);
            }
        }

        $rows = $this->orderDummyRows($rows, $orderby);
        $rows = $this->sliceDummyRows($rows, $limit, $offset);
        return $fields ? ArrayHelper::cutFields($rows, $fields) : $rows;
    }

    /**
     * Главная функция получения данных
     *
     * @param string|array $fields Не обязательный параметр. Поля (через запятую строкой или массивом) из таблицы
     * @param integer $limit . Не обязательный параметр. Кол-во результирующих данных.
     * @param integer $offset . Не обязательный параметр. Смещение от началы выборки.
     * @param string $orderby . Сортировка
     * @return array<int, array<string, mixed>> Результат выборки - array of database rows
     *
     * @throws Exception Если условие для выборки предварительно не задано
     */
    private function doGet($fields = null, $limit = null, $offset = null, $orderby = null)
    {
        // Приводим перечисление полей к строке
        if ($fields && !is_array($fields)) {
            $fields = ArrayHelper::stringCommasToArray($fields);
        }
        $allFields = $this->getFields();
        if ($this instanceof DummyModel) {
            $rows = $this->getDummyRows($this, $fields, $limit, $offset, $orderby);
            $this->clear(!!$this->aggregateFunc || $this->persistent);
            return $rows;
        }

        $where = $this->getWhereString();
        $where = $this->appendSoftDeleteWhere($where, $allFields);
        $whereExpr = $where ? "WHERE " . $where : '';
        $joinsExpr = $this->buildJoinsExpression();
        [$orderby, $orderbyDesc] = $this->resolveOrderByParts($orderby);

        $asAlias = !empty($this->alias) ? " AS " . $this->alias : "";

        try {
            $selectedFields = $this->resolveSelectedFields($fields);
            $selectExpr = $this->aggregateFunc ?: implode(", ", $selectedFields);

            if ($orderby && strpos($orderby, ',') === false) {
                $orderby = $this->normalizeSqlOperand($orderby);
            }

            $groupBy = $this->groupBy;
            if ($groupBy) {
                $groupBy = $this->normalizeSqlOperand($groupBy);
            }
            // Делаем запрос
            $rows = $this->db->select(
                'SELECT '
                . $selectExpr
                . ' FROM ?_' . $this->table() . $asAlias
                . ($joinsExpr ? ' ' . $joinsExpr : '')
                . ($whereExpr ? ' ' . $whereExpr : '')
                . ($groupBy ? ' GROUP BY ' . $groupBy : '')
                . ($orderby ? ' ORDER BY ' . $orderby . ' ' . $orderbyDesc : '')
                . '{ LIMIT ?d}{ OFFSET ?d}' . $this->lock,
                $limit ?: DBSIMPLE_SKIP,
                $offset ?: DBSIMPLE_SKIP
            );
        } finally {
            // Очищаем переменные текущей цепочки
            // Если задана агрегатная ф-ия, то чистим только её - из модели далее можно будет достать список
            $this->clear(!!$this->aggregateFunc || $this->persistent);
        }
        // Возвращаем результат
        return $rows ?: [];
    }

    public function retrieved(array $data)
    {
        if (ArrayHelper::isArrayOfArrays($data)) {
            $data = $this->fire(Model::EVENT_RETRIEVED, $data);
        } else {
            $data = $this->fire(Model::EVENT_RETRIEVED, [$data]);
            $data = array_shift($data);
        }
        return $data;
    }

    private function mergeMappedWith(ModelAbstract $model): ModelAbstract
    {
        $model->mergeWithArray($this->getWithArray());
        return $model;
    }

    /**
     * @param class-string<Model> $class
     */
    private function createMappedModel(string $class, array $data): Model
    {
        /** @var Model $model */
        $model = new $class($data);
        $this->inheritModel($model, true);
        return $model;
    }

    /**
     * @param class-string<Model> $class
     */
    private function createMappedModelList(string $class, array $data): ModelList
    {
        $modelList = new ModelList($class);
        $this->inheritModel($modelList, true);

        foreach ($data as $index => $modelData) {
            $modelList[$index] = $this->createMappedModel($class, $modelData);
        }

        return $modelList;
    }

    /**
     * @return ModelList<Model>|Model
     */
    private function mapRetrievedDataToModels(array $data)
    {
        $class = get_class($this);
        $data = $this->retrieved($data);

        if (ArrayHelper::isArrayOfArrays($data)) {
            return $this->mergeMappedWith($this->createMappedModelList($class, $data));
        }

        return $this->mergeMappedWith($this->createMappedModel($class, $data));
    }

    /**
     * Сконвертировать массив в модель
     * @param array $data
     * @return Model|ModelList
     * @throws Exception
     */
    public function map(array $data)
    {
        return $this->mapRetrievedDataToModels($data);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function extractFirstRawRow(array $rows): array
    {
        return $rows ? array_shift($rows) : [];
    }

    /**
     * @return array<string, mixed>|mixed
     */
    private function normalizeValueSource($value)
    {
        if ($value instanceof Model) {
            return $value->toArray([
                self::FLAG_DEPENDENCIES => false,
                self::FLAG_EMPTY => false,
                self::FLAG_HIDDEN => true,
                self::FLAG_COMPUTABLE => false
            ]);
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|mixed $value
     * @return mixed|null
     */
    private function extractScalarValue($value, string $field)
    {
        return $value && is_array($value) ? $value[$field] : null;
    }

    /**
     * Получить одно значение
     *
     * @param string $field Поле, значение которого надо получить
     * @return mixed|null
     * @throws Exception
     */
    public function value($field, $orderby = null)
    {
        [, , $offset, $orderby] = $this->resolveCollectionArgs(null, null, null, $orderby);
        $value = $this->first($field, $orderby);
        return $this->extractScalarValue($this->normalizeValueSource($value), $field);
    }

    /**
     * Получить первое значение
     *
     * @param array|string $fields Поля для выборки
     * @noinspection PhpDocMissingThrowsInspection
     * @return static
     */
    public function first($fields = null, $orderby = null)
    {
        [$fields, , $offset, $orderby] = $this->resolveCollectionArgs($fields, null, 0, $orderby);
        $rows = $this->doGet($fields, 1, $offset, $orderby);
        $first = $this->extractFirstRawRow($rows);
        /** @var static $model */
        $model = $this->mapRetrievedDataToModels($first ?: []);
        return $model;
    }

    private function resolveColumnFields($fields, ?string $key)
    {
        if (!$key || $fields === "*") {
            return $fields;
        }

        if (!is_array($fields)) {
            $fields = ArrayHelper::stringCommasToArrayCheckBraces($fields);
        }

        if (!in_array($key, $fields)) {
            $fields[] = $key;
        }

        return $fields;
    }

    private function normalizeColumnKey(?string $key): ?string
    {
        if ($key && str_contains($key, ".")) {
            return substr($key, strrpos($key, ".") + 1);
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: int|string, 1: string, 2: mixed}|null
     */
    private function resolveColumnEntry(array $row, int|string $defaultKey, ?string $key): ?array
    {
        if ($key) {
            $defaultKey = $row[$key];
            unset($row[$key]);
        }

        $fields = array_keys($row);
        if (!isset($fields[0])) {
            return null;
        }

        $field = $fields[0];
        return [$defaultKey, $field, array_shift($row)];
    }

    /**
     * Получить колонку (столбец)
     *
     * @param string $fields Поля выборки
     * @param string $key Ключевое поле
     * @param integer $limit Кол-во результирующих строк
     * @param integer $offset Смещение от начала выборки
     * @param string $orderby Сортировка
     * @return array<int|string, mixed> Column values, optionally keyed by $key field
     * @throws Exception
     */
    public function column($fields = null, $key = null, $limit = null, $offset = null, $orderby = null)
    {
        [$fields, $limit, $offset, $orderby] = $this->resolveCollectionArgs($fields, $limit, $offset, $orderby);
        $fields = $this->resolveColumnFields($fields, $key);
        $rows = $this->doGet($fields, $limit, $offset, $orderby);
        $ret = [];
        $key = $this->normalizeColumnKey($key);

        foreach ($rows as $k => $row) {
            $entry = $this->resolveColumnEntry($row, $k, $key);
            if ($entry === null) {
                continue;
            }

            [$columnKey, $field, $value] = $entry;
            $ret[$columnKey] = $this->castDbToExternal($field, $value);
        }
        return $ret;
    }

    /**
     * Получить список
     *
     * @param string|array|null $fields Поля выборки
     * @param integer $limit Кол-во результирующих строк
     * @param integer $offset Смещение от начала выборки
     * @param string $orderby Сортировка
     * @return ModelList
     * @throws Exception
     */
    public function collection($fields = null, $limit = null, $offset = null, $orderby = null): ModelList
    {
        [$fields, $limit, $offset, $orderby] = $this->resolveCollectionArgs($fields, $limit, $offset, $orderby);
        $rows = $this->doGet($fields, $limit, $offset, $orderby);
        return $rows ? $this->map($rows) : new ModelList(get_class($this));
    }

    public function all($fields = null, $orderby = null): ModelList
    {
        return $this->collection($fields, null, null, $orderby);
    }

    public function chunk($cb, $chunkSize, $fields = null, $orderby = null)
    {
        for ($i = 0;; $i++) {
            $collection = $this->collection($fields, $chunkSize, $i * $chunkSize, $orderby);
            if ($collection->isEmpty()) {
                break;
            }
            call_user_func($cb, $collection, $i);
            if (count($collection) < $chunkSize) {
                break;
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>> Raw collection without model wrapping
     */
    public function collectionRaw($fields = null, $limit = null, $offset = null, $orderby = null)
    {
        [$fields, $limit, $offset, $orderby] = $this->resolveCollectionArgs($fields, $limit, $offset, $orderby);
        return $this->retrieved($this->doGet($fields, $limit, $offset, $orderby));
    }

    /**
     * @return array<string, mixed> Raw first row without model wrapping
     */
    public function firstRaw($fields = null, $orderby = null)
    {
        [$fields, , $offset, $orderby] = $this->resolveCollectionArgs($fields, null, 0, $orderby);
        $rows = $this->doGet($fields, 1, $offset, $orderby);
        return $this->extractFirstRawRow($rows);
    }

    private function castSimpleValue(string $field, mixed $value, bool $stringify): mixed
    {
        $value = $this->castDbToExternal($field, $value);
        if ($stringify && $this instanceof StringableInterface) {
            $value = $this::stringValueIfExists($field, $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function castSimpleRow(array $row, bool $stringify): array
    {
        foreach ($row as $field => $value) {
            $row[$field] = $this->castSimpleValue($field, $value, $stringify);
        }

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function castSimpleRows(array $rows, bool $stringify): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index] = $this->castSimpleRow($row, $stringify);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>> Simple collection with casted values
     */
    public function collectionSimple($stringify = true, $fields = null, $limit = null, $offset = null, $orderby = null)
    {
        [$fields, $limit, $offset, $orderby] = $this->resolveCollectionArgs($fields, $limit, $offset, $orderby);
        $rows = $this->collectionRaw($fields, $limit, $offset, $orderby);
        return $this->castSimpleRows($rows, $stringify);
    }

    private function buildAggregateExpression(string $func, string $field): string
    {
        return sprintf($func . '(%s) AS aggregate', $this->escapeField($field));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return mixed|null
     */
    private function extractAggregateResult(array $rows)
    {
        return $rows[0]['aggregate'] ?? null;
    }

    private function aggregate($func, $field)
    {
        $this->aggregateFunc = $this->buildAggregateExpression($func, $field);
        $rows = $this->doGet(null);
        return $this->extractAggregateResult($rows);
    }

    private function aggregateNumeric(string $func, string $field): int|float
    {
        return 0 + $this->aggregate($func, $field);
    }

    public function count($field = '*')
    {
        return $this->aggregateNumeric('COUNT', $field);
    }

    public function avg($field)
    {
        return $this->aggregateNumeric('AVG', $field);
    }

    public function sum($field)
    {
        return $this->aggregateNumeric('SUM', $field);
    }

    public function min($field)
    {
        return $this->aggregateNumeric('MIN', $field);
    }

    public function max($field)
    {
        return $this->aggregateNumeric('MAX', $field);
    }
}
