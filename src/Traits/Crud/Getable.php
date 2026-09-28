<?php

namespace Jam\Models\Traits\Crud;

use Exception;
use Jam\Models\Model;
use Jam\Models\ModelAbstract;
use Jam\Models\ModelList;
use Jam\Models\StringableInterface;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Storage\Criteria;
use Jam\Models\Storage\Query;
use Jam\Models\Storage\SqlStorage;

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

    protected ?string $orderBy = null;
    protected ?int $limitValue = null;
    protected ?int $offsetValue = null;

    /**
     * Очищает переменные цепочки
     * @return $this
     */
    public function clear($keepConditions = false)
    {
        if (!$keepConditions) {
            $this->where = [];
            $this->joins = [];
            $this->leftJoins = [];
            $this->rightJoins = [];
            $this->lock = '';
            $this->groupBy = '';
            $this->orderBy = null;
            if ($this->useTrashed()) {
                $this->collectionWithDeleted = false;
            }
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
     * Задаёт условие выборки (несколько вызовов соединяются через AND).
     *
     * Условие-массив — понятно любому хранилищу (SQL, DummyModel, ...), см. Storage\Criteria:
     *   where(['user_id' => 5, 'status' => [1, 2], 'views' => ['>=' => 10]])
     *
     * SQL-фрагмент с плейсхолдерами DbSimple — только для SQL-хранилища:
     *   where('id = ?d AND dt > NOW() - INTERVAL ?d DAY', $id, $period)
     *
     * @param string|array<string, mixed>|Criteria|null $condition
     * @return static
     */
    public function where(string|array|Criteria|null $condition): static
    {
        if (is_array($condition)) {
            $condition = $condition ? new Criteria($condition) : null;
        }
        if ($condition instanceof Criteria) {
            $this->where[] = $condition;
        } elseif ($condition) {
            $this->where[] = func_get_args();
        }
        return $this;
    }

    /**
     * @param 'joins'|'leftJoins'|'rightJoins' $field
     * @param array<int, mixed> $args Аргументы join(): строка с плейсхолдерами и значения,
     *                                либо один массив готовых выражений
     */
    private function addJoin(string $field, array $args): static
    {
        $expression = $args[0] ?? null;
        if ($expression) {
            $this->{$field} = array_merge($this->{$field}, is_array($expression) ? $expression : [$args]);
        }
        return $this;
    }

    public function join(array|string|null $expression): static
    {
        return $this->addJoin('joins', func_get_args());
    }

    public function leftJoin(array|string|null $expression): static
    {
        return $this->addJoin('leftJoins', func_get_args());
    }

    public function rightJoin(array|string|null $expression): static
    {
        return $this->addJoin('rightJoins', func_get_args());
    }

    public function groupBy(string $groupBy): static
    {
        $this->groupBy = $groupBy;
        return $this;
    }

    public function getWhere()
    {
        return $this->where;
    }

    public function setWhere(array $where)
    {
        $this->where = $where;
    }

    /**
     * Получить фильтр модели в виде строки WHERE SQL-запроса (без фильтра SoftDeletes)
     */
    public function getWhereString(): string
    {
        return (new SqlStorage())->whereSql($this, new Query($this->table(), $this->alias, $this->where));
    }

    public function getWhereSubstitute()
    {
        $whereStr = $this->getWhereString();
        return $whereStr ? $this->getDb()->subquery($whereStr) : DBSIMPLE_SKIP;
    }

    /**
     * Колонка SoftDeletes, по которой нужно отсечь удалённые записи (null — не нужно).
     * Условие зависит от трейта, а не от списка полей: список может быть урезан через
     * cutFields()/with('rel(a,b)'). Явное условие по колонке отключает фильтр.
     */
    private function softDeleteColumnFilter(): ?string
    {
        if (!$this->useTrashed() || $this->collectionWithDeleted) {
            return null;
        }
        $column = $this->getDeletedAtColumn();
        foreach ($this->where as $condition) {
            $mentioned = $condition instanceof Criteria
                ? $condition->mentions($column)
                : str_contains((string) $condition[0], $column);
            if ($mentioned) {
                return null;
            }
        }
        return $column;
    }

    /**
     * Поля выборки: явно заданные или outFields; к ним добавляются локальные ключи связей из with().
     *
     * @param array<int, string>|null $fields
     * @return array<int, string>|null null — все поля
     */
    private function resolveSelectedFields(?array $fields): ?array
    {
        $fields = $fields ?: ($this->outFields ?: null);
        if (!$fields) {
            return null;
        }
        foreach ($fields as $field) {
            if ($field === '*' || ($this->alias && $field === $this->alias . '.*')) {
                return $fields;
            }
        }

        $alias = $this->getAlias();
        foreach ($this->getWithArray() as $withRelation) {
            if ($withRelation !== self::EXPLICIT) {
                $fields[] = ($alias ? $alias . '.' : '') . $withRelation->localKey;
            }
        }
        return array_values(array_unique($fields));
    }

    /**
     * Снимок текущего состояния построителя для хранилища.
     *
     * @param string|array<int, string>|null $fields
     */
    public function buildQuery($fields = null, $limit = null, $offset = null, $orderby = null): Query
    {
        // Запятые внутри скобок — часть выражения: COALESCE(a, b)
        if ($fields && !is_array($fields)) {
            $fields = array_values(array_filter(
                array_map('trim', ArrayHelper::stringCommasToArrayCheckBraces($fields)),
                static fn(string $field) => $field !== ''
            ));
        }

        return new Query(
            table: $this->table(),
            alias: (string) $this->alias,
            where: $this->where,
            fields: $this->resolveSelectedFields($fields ?: null),
            joins: array_filter(['JOIN' => $this->joins, 'LEFT JOIN' => $this->leftJoins, 'RIGHT JOIN' => $this->rightJoins]),
            groupBy: $this->groupBy,
            orderBy: $orderby,
            limit: $limit,
            offset: $offset,
            lock: $this->lock,
            softDeleteColumn: $this->softDeleteColumnFilter(),
        );
    }

    /**
     * Главная функция получения данных: строки «как в БД».
     *
     * @return array<int, array<string, mixed>>
     */
    private function doGet($fields = null, $limit = null, $offset = null, $orderby = null)
    {
        try {
            return $this->storage()->select($this, $this->buildQuery($fields, $limit, $offset, $orderby));
        } finally {
            // Очищаем переменные текущей цепочки (у персистентного построителя — нет)
            $this->clear($this->persistent);
        }
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

    private function aggregate($func, $field)
    {
        try {
            return $this->storage()->aggregate($this, $this->buildQuery(), $func, $field);
        } finally {
            // Агрегат не сбрасывает условия: следом можно выбрать страницу теми же условиями
            $this->clear(true);
        }
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
