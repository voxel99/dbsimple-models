<?php

/**
 * Базовый класс для моделей
 */

namespace Jam\Models;

use Jam\Models\Traits\Crud\Deletable;
use Jam\Models\Traits\Crud\Entity;
use Jam\Models\Traits\Crud\Getable;
use Jam\Models\Traits\Crud\Insertable;
use Jam\Models\Traits\Crud\Updatable;
use Jam\Models\Traits\Internal\Convertable;
use Jam\Models\Traits\Internal\DynamicProps;
use Jam\Models\Traits\Internal\Relatable;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Utils\Code;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * @phpstan-consistent-constructor
 */
class Model extends ModelAbstract implements IRelationList
{
    use Entity;
    use Relatable;
    use DynamicProps;
    use Convertable;
    use Getable;
    use Deletable;
    use Insertable;
    use Updatable;

    public const string DB_MASTER = 'master';
    public const string DB_SLAVE = 'slave';

    public const string VALUE_YES = 'yes';
    public const string VALUE_NO = 'no';

    public const string EVENT_RETRIEVED = 'retrieved';
    public const string EVENT_RESTORING = 'restoring';
    public const string EVENT_RESTORED = 'restored';
    public const string EVENT_DELETING = 'deleting';
    public const string EVENT_DELETED = 'deleted';
    public const string EVENT_CREATING = 'creating';
    public const string EVENT_CREATED = 'created';
    public const string EVENT_UPDATING = 'updating';
    public const string EVENT_UPDATED = 'updated';

    public const string SKIP_UPDATE = 'SKIP_UPDATE';
    public const string PIVOT_FIELD = '@pivot';
    public const string TITLES_FIELD = '@titles';
    public const string IDENTIFIERS_FIELD = '@ids';

    public const string SAVE_USE_INSERT = 'use_insert';
    public const string SAVE_USE_INSERT_IGNORE = 'use_insert_ignore';
    public const string SAVE_USE_INSERT_DKU = 'use_insert_dku';
    public const string SAVE_USE_CHECK_OLD = 'use_check_old';
    public const string SAVE_PARAMS_ALL = 'all';

    public const int CASE_I = 0;
    public const int CASE_R = 1;
    public const int CASE_D = 2;
    public const int CASE_V = 3;
    public const int CASE_T = 4;
    public const int CASE_P = 5;

    public const string METHOD_FILTER_COMPUTED = "filterComputed";

    /**
     * Поля таблицы
     * @var string
     */
    protected string $fields = '';

    /**
     * Защищённые от изменения поля таблицы, их нельзя менять в прикладном поле
     * @var string
     */
    protected string $guarded = '';

    /**
     * Скрытые поля таблицы, они не возвращаются в прикладной код, если не указать явно
     * @var string
     */
    protected string $hidden = '';

    /**
     * Вычислимые поля модели (реализуются как методы)
     * @var string
     */
    protected string $computed = '';

    protected string $explicit = '';

    /**
     * Вычислимые поля, которые должны быть сохранены или обновлены в БД
     * @var string
     */
    protected string $updatable = '';

    /**
     * Дополнительные поля для хранения временных данных вместе с моделью
     * @var string
     */
    protected string $extra = '';

    /**
     * Приведение типов полей: ['int' => 'id, user_id', 'json' => 'settings', ...]
     * Нетипизированное свойство: наследники объявляют его как `protected $types = [...]`.
     * @var array<string, string|array<int|string, mixed>>
     */
    protected $types = [];

    /**
     * Модель будет сохранять внутреннее состояние после возврата результата
     * @var bool
     */
    protected bool $persistent = false;

    /**
     * Статический массив для хранения признаков проверки класса
     * Для избежания повторных проверок
     *
     * @var array
     */
    private static array $thisModelChecks = [];

    /**
     * Массив для связывания нестандартных методов-описателей полей и их идентификаторов
     * @var array
     */

    protected array $thisModelTitles = [];

    /**
     * Обработанный массив связок для всех моделей
     * @var array
     */
    private static array $thisModelTitlesInternal = [];

    /**
     * Model constructor
     * @noinspection PhpDocMissingThrowsInspection
     * @param array|object|null $data Массив или объект с данными модели (и возможно отношений)
     */
    public function __construct(mixed $data = null)
    {
        parent::__construct();
        if ($data) {
            if (is_object($data)) {
                $this->fromObject($data);
            } elseif (is_array($data)) {
                $this->fromArray($data);
            } elseif (is_scalar($data) && $this instanceof ScalarableInterface) {
                $scalarModel = $this::fromScalar($data);
                if (!$scalarModel instanceof self) {
                    throw new ModelException(sprintf('%s::fromScalar must return %s', static::class, self::class));
                }
                $this->fromArray($scalarModel->toArray());
            } else {
                throw new ModelException(get_class($this) .
                    ": Данные для инициализации должны быть представлены объектом или массивом");
            }
        }

        $class = get_class($this);
        if (!isset(self::$thisModelChecks[$class])) {
            $fields = $this->getFields();
            if ($fields) {
                $denyNames = ArrayHelper::stringCommasToArray(
                    'pk,fk,table,alias,fields,guarded,hidden,computed,extra,updatable,persistent,_where,with,flagsMap,
                    connection,hasMany,hasOne,belongs'
                );
                $denyList = array_intersect($fields, $denyNames);
                if ($denyList) {
                    throw new ModelException(sprintf(get_class($this) .
                        ": Модель не должна содержать полей: %s", implode(",", $denyList)));
                }
            }
            self::$thisModelChecks[$class] = true;
        }
    }

    /**
     * Установить персистентность модели
     * @param bool $flag
     * @return $this
     */
    public function persistent(bool $flag = true): static
    {
        $this->persistent = $flag;
        return $this;
    }

    public function useTrashed(): bool
    {
        return false;
    }

    /**
     * Текущее время в формате БД. Используется Timestamps, SoftDeletes и updateRaw().
     * Переопределите в базовой модели проекта, если нужен другой источник времени.
     */
    public static function freshTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function dbEsc(string $field): string
    {
        if (str_starts_with($field, 'FIELD')) {
            $esc = $field;
        } else {
            $parts = explode(".", $field);
            if (count($parts) === 1) {
                $esc = sprintf("`%s`", trim($field, "`"));
            } else {
                $subparts = explode(' ', $parts[1]);
                $subparts[0] = sprintf("`%s`", trim($subparts[0], "`"));
                $parts[0] = sprintf("`%s`", trim($parts[0], "`"));
                $parts[1] = implode(' ', $subparts);
                $esc = implode(".", $parts);
            }
        }
        return $esc;
    }

    public function debugInfo(bool $getData = true): stdClass
    {
        return (object)[
            'guarded' => $this->getGuarded(),
            'hidden' => $this->getHidden(),
            'computed' => $this->getComputed(),
            'connection' => $this->jamConnection,
            'data' => $getData ? $this->toArray() : '~~~ not get ~~~',
            'relations' => $this->getAllRelations(),
            '__data_db' => $this->jamDataDb,
            '__data_ex' => $this->jamDataEx,
        ];
    }

    public function __debugInfo(): array
    {
        return [
            '~~type~~' => get_class($this),
            'data' => $this->toArray([
                self::FLAG_HIDDEN => true,
                self::FLAG_EMPTY => true,
                self::FLAG_UPDATABLE => true,
                self::FLAG_DEPENDENCIES => true,
                self::FLAG_EXTRA => true,
            ])
        ];
    }

    /**
     * Получить экземпляр модели
     * @param bool $persistent
     * @return $this
     * @noinspection PhpDocMissingThrowsInspection
     */
    public static function instance(bool $persistent = false): static
    {
        return new static()->persistent($persistent);
    }

    public static function subquery(string $query, ...$args): \Jam\DbSimple\SubQuery
    {
        return static::getDbConnection()->subquery($query, ...$args);
    }

    public function deleteOld(string $alias, Relation $R, Model $oldModel): void
    {
        /** @var ModelAbstract $oldRelation */
        $oldRelation = $oldModel->{$alias};
        /** @var ModelAbstract $thisRelation */
        $thisRelation = $this->{$alias};
        $thisId = $this->{$this->pk()};
        if (!$oldRelation->isEmpty() && $oldRelation instanceof ModelList) {
            $oldItemIds = [];
            $newItemIds = [];
            $pivot = $R->getPivot();
            /** @var Model $oldItem */
            foreach ($oldRelation as $oldItem) {
                $pk = $oldItem->pk();
                $oldItemIds[get_class($oldItem)][] = $oldItem->$pk;
            }
            if ($thisRelation instanceof ModelList && !$thisRelation->isEmpty()) {
                foreach ($thisRelation as $item) {
                    $pk = $item->pk();
                    $newItemIds[get_class($item)][] = $item->$pk;
                }
            }
            foreach ($oldItemIds as $className => $ids) {
                if (!empty($newItemIds[$className])) {
                    $oldItemIds[$className] = array_diff($ids, $newItemIds[$className]);
                }
            }
            if (!empty($oldItemIds)) {
                foreach ($oldItemIds as $className => $ids) {
                    if (!empty($ids)) {
                        if ($pivot) {
                            $pivot->where(
                                '?# = ? AND ?# IN (?a)',
                                $R->pivotLocalKey(),
                                $thisId,
                                $R->pivotOtherKey(),
                                $ids
                            )->collection()->delete();
                        } else {
                            /** @var Model $relObject */
                            $relObject = new $className();
                            $relObject->where(
                                '?# IN (?a)',
                                $relObject->pk(),
                                $ids
                            )->collection()->delete();
                        }
                    }
                }
            }
        }
    }

    private function saveRelation(Relation $R, array $params = []): void
    {
        $alias = $R->alias();
        if ($this->$alias instanceof ModelAbstract && !is_null($this->$alias) && !empty($this->$alias->toArray())) {
            $this->inheritModel($this->$alias);
            $relationParams = $params[self::SAVE_PARAMS_ALL] ?? [];
            if (isset($params[$alias])) {
                $relationParams = array_merge($relationParams, $params[$alias]);
            }
            $id = $this->$alias->save($relationParams);
            if ($R->isTypeBelongs()) {
                if ($this->{$R->localKey()} !== $id) {
                    $this->{$R->localKey()} = $id;
                    if (!empty($this->{$this->pk()})) {
                        $this->update($R->localKey());
                    }
                }
            }
        }
    }

    public function getClassNameByAlias(string $alias): string
    {
        $className = '';
        if (!empty($this->{$alias})) {
            if ($this->{$alias} instanceof ModelList) {
                $className = $this->{$alias}->getClass();
            } else {
                $className = get_class($this->{$alias});
            }
        }
        return $className;
    }

    /**
     * DummyModel только для чтения: связанные записи должны совпадать со строками dummyRows().
     *
     * @throws ModelException
     */
    public function checkDummyModelRelation(string $alias): void
    {
        $related = $this->{$alias};
        $className = $this->getClassNameByAlias($alias);
        if (empty($related) || !$className) {
            return;
        }

        /** @var Model&DummyModel $dummyModel */
        $dummyModel = new $className();
        $pk = $dummyModel->pk();
        $rowsByPk = array_column($dummyModel->dummyRows(), null, $pk);
        // belongs/hasOne — одна модель, hasMany — ModelList
        $checkedRows = $related instanceof ModelList ? $related->toArray() : [$related->toArray()];

        foreach ($checkedRows as $checkedRow) {
            $row = $rowsByPk[$checkedRow[$pk] ?? null] ?? null;
            if ($row === null || !ArrayHelper::isSubset($row, $checkedRow)) {
                throw new ModelException('DummyModel is readonly');
            }
        }
    }

    public function relationIsDummyModel(string $alias): bool
    {
        $className = $this->getClassNameByAlias($alias);
        if ($className) {
            return Code::isImplements($className, DummyModel::class);
        }
        return false;
    }

    /**
     * Сохранить (обновить или вставить) запись
     * @param array $params
     * @param bool $saveRelations
     * @return int ID записи
     * @throws ModelException
     */
    public function save(array $params = [], bool $saveRelations = true): int
    {
        $modelParams = $params[self::SAVE_PARAMS_ALL] ?? [];
        foreach (
            [
             self::SAVE_USE_INSERT_DKU,
             self::SAVE_USE_INSERT_IGNORE,
             self::SAVE_USE_INSERT,
             self::SAVE_USE_CHECK_OLD
            ] as $p
        ) {
            if (isset($params[$p])) {
                $modelParams[$p] = $params[$p];
            }
        }

        $insertDku = $modelParams[self::SAVE_USE_INSERT_DKU] ?? false;
        $insertIgnore = $modelParams[self::SAVE_USE_INSERT_IGNORE] ?? false;
        $useInsert = !empty($modelParams[self::SAVE_USE_INSERT]) || $insertIgnore || $insertDku;
        $useCheckOld = !empty($modelParams[self::SAVE_USE_CHECK_OLD]);

        if ($saveRelations) {
            // Сохраняем модели в отношении belongs
            foreach ($this->belongs as $R) {
                if ($this->relationIsDummyModel($R->alias())) {
                    $this->checkDummyModelRelation($R->alias());
                    if ($this->{$R->alias()} instanceof Model) {
                        $this->{$R->localKey()} = $this->{$R->alias()}->{$R->otherKey()};
                    }
                } else {
                    $this->saveRelation($R);
                }
            }
        }

        $pk = $this->pk();
        $id = $this->{$pk} ?: 0;

        // Hack
        if ($pk !== 'id' && $id) {
            $insertDku = $useInsert = true;
        }
        // End of hack

        if ($id) {
            $newId = $useInsert
                ? ($insertDku ? $this->insertD() : ($insertIgnore ? $this->insertI() : $this->insert()))
                : $this->update();
        } else {
            $newId = $insertDku ? $this->insertD() : ($insertIgnore ? $this->insertI() : $this->insert());
            $this->{$this->pk()} = $newId;
        }
        $initRelations = $this->getInitedRelations();

        if ($useCheckOld && $id && $initRelations) {
            $oldModel = static::instance()->id($id)->with(array_keys($initRelations))->first();
            /**
             * @var string $alias
             * @var Relation $R
             */
            foreach ($initRelations as $alias => $R) {
                if (!empty($oldModel->{$alias})) {
                    $this->deleteOld($alias, $R, $oldModel);
                }
            }
        }

        if ($saveRelations) {
            // Сохраняем модели в отношении много-ко-многим
            foreach ($this->hasMany as $R) {
                if ($R->getPivot()) {
                    if ($this->relationIsDummyModel($R->alias())) {
                        $this->checkDummyModelRelation($R->alias());
                    } else {
                        $this->saveRelation($R);
                    }
                }
            }

            $this->setRelationKeys();
            $relations = $this->getAllRelations();
            // Сохраняем остальные модели и pivot в отношениях много-ко-многим
            foreach ($relations as $R) {
                if ($R->isTypeBelongs()) {
                    continue;
                }
                $pivot = $R->getPivotModelList();
                if ($pivot && !$pivot->isEmpty()) {
                    $pivot->save([
                        self::SAVE_USE_INSERT => $useInsert,
                        self::SAVE_USE_INSERT_DKU => false,
                        self::SAVE_USE_INSERT_IGNORE => true
                    ]);
                } else {
                    if ($this->relationIsDummyModel($R->alias())) {
                        $this->checkDummyModelRelation($R->alias());
                    } else {
                        $this->saveRelation($R);
                    }
                }
            }
        }
        return (int) $newId;
    }

    public function isEmpty(): bool
    {
        $pk = $this->pk();
        return empty($this->{$pk}) && empty($this->toArray([
            self::FLAG_DEPENDENCIES => false,
            self::FLAG_COMPUTABLE => false,
            self::FLAG_HIDDEN => true,
            self::FLAG_INTERNAL_DATABASE => true
        ]));
    }

    public function isExists(): bool
    {
        return !$this->isEmpty();
    }

    // Этот метод будет переопределяться, если модель использует trait SoftDeletes

    /**
     * @throws ModelException
     */
    public function forceDelete(bool $withHASRelations = false, bool $withBELONGSRelation = false): mixed
    {
        return $this->delete($withHASRelations, $withBELONGSRelation);
    }

    // Этот метод будет переопределяться, если модель использует trait SoftDeletes
    public function isForceDeleting(): bool
    {
        return true;
    }

    public function __toString(): string
    {
        return "{" . get_class($this) . "}";
    }

    public function updateByArray(array $upd): int
    {
        foreach ($upd as $k => $v) {
            $this->$k = $v;
        }
        return $this->update(array_keys($upd));
    }

    public static function updateRaw(array $data, int|array $id): int
    {
        $instance = static::instance();
        if (
            isset($instance->columnUpdated) &&
            in_array($instance->columnUpdated, $instance->getFields()) &&
            !isset($data[$instance->columnUpdated])
        ) {
            $data[$instance->columnUpdated] = static::freshTimestamp();
        }
        return $instance->db->query(
            'UPDATE ?# SET ?a WHERE ?# IN (?a)',
            "?_" . $instance->table(),
            $data,
            $instance->pk(),
            (array)$id
        );
    }

    public static function insertOnDuplicateKeyUpdateRaw(array $ins): int
    {
        $instance = static::instance();
        $duplicateArr = [];
        if (!empty($ins[0])) {
            foreach ($ins[0] as $f => $v) {
                if ($f !== $instance->pk()) {
                    $duplicateArr[] = '`' . $f . '` = VALUES(`' . $f . '`)';
                }
            }

            return $instance->db->query(
                'INSERT INTO ?# (?#) VALUES (?a) ON DUPLICATE KEY UPDATE ' . implode(", ", $duplicateArr),
                "?_" . $instance->table(),
                array_keys($ins[0]),
                array_values($ins)
            );
        }
        return 0;
    }

    public static function createOrUpdate(array|object $data): bool
    {
        $instance = static::instance();
        $data = (array)$data;
        if (!isset($data[$instance->pk()])) {
            throw new ModelException('Data is not contains primary key: ' . $instance->pk());
        }
        $pk = $data[$instance->pk()];
        $entity = $instance->id($pk)->first($instance->pk());
        $instance->fromArray($data)->save([Model::SAVE_USE_INSERT_DKU => $entity->isEmpty()]);
        return $entity->isEmpty();
    }

    public function __serialize(): array
    {
        $serialized = $this->toArray(':dhx');
        $serialized['_connection'] = $this->jamConnection;
        return $serialized;
    }

    public function __unserialize(array $data): void
    {
        $copy = self::instance();
        foreach (['hasMany', 'hasOne', 'belongs'] as $relationType) {
            $this->setRelations($relationType, $copy->getRelations($relationType));
        }
        $this->jamConnection = $data['_connection'];
        unset($data['_connection']);
        // Types может задаваться в конструкторе, который здесь не вызывается
        $this->types = $copy->types;

        $this->fromArray($data, true);
        $this->db($this->jamConnection);
    }

    public function getDiff(mixed $other): array
    {
        if ($other instanceof self) {
            $other = $other->toArray();
        } elseif (is_object($other)) {
            $other = ArrayHelper::convertFromObject($other);
        }
        $self = $this->toArray();
        $keys = array_diff(array_keys($other), $this->getRelationAliases(), ['created_at']);
        if (!empty($this->columnCreated)) {
            $keys = array_diff($keys, [$this->columnCreated]);
        }
        $self = array_intersect_key($self, array_flip($keys));
        return array_diff_assoc($self, $other);
    }

    public static function clearExcessFields(mixed $object): mixed
    {
        $instance = self::instance();
        $fields = array_merge($instance->getFields(), $instance->getExtra());
        $relations = $instance->getRelationClasses();
        $isObject = is_object($object);
        $ret = [];
        if (is_scalar($object)) {
            return $object;
        }

        foreach ((array)$object as $k => $v) {
            if (is_null($v)) {
                continue;
            }
            if (is_numeric($k)) {
                $ret[$k] = self::clearExcessFields($v);
            } else {
                if (in_array($k, $fields)) {
                    $ret[$k] = $v;
                } else {
                    if (!empty($relations[$k])) {
                        $class = $relations[$k]($object);
                        if ($class) {
                            $M = new $class();
                            $ret[$k] = $M::clearExcessFields($v);
                        }
                    }
                }
            }
        }
        if ($isObject) {
            $ret = (object)$ret;
        }
        return $ret;
    }

    public function setFlags(string $name, array $newValues): void
    {
        $flags = $this->$name ?: (object)[];
        foreach ($newValues as $k => $v) {
            $flags->$k = $v;
        }
        $this->$name = $flags;
    }

    public function __getTitleMethod(string $field): string
    {
        return $this->thisModelTitles[$field] ?? Code::getDescriptionByField($field);
    }

    private function retrieveTitles(): array
    {
        if (!isset(self::$thisModelTitlesInternal[static::class])) {
            $fields = $this->getFields();
            self::$thisModelTitlesInternal[static::class] = [];
            $staticMethods = array_map(function ($method) {
                return $method->name;
            }, new ReflectionClass(static::class)->getMethods(ReflectionMethod::IS_STATIC));
            foreach ($fields as $field) {
                $method = $this->__getTitleMethod($field);
                if (in_array($method, $staticMethods)) {
                    self::$thisModelTitlesInternal[static::class][$field] = static::$method();
                }
            }
        }
        return self::$thisModelTitlesInternal[static::class];
    }

    public function __getAllTitles(array|string|null $fields = null): array
    {
        $titles = $this->retrieveTitles();
        if (!empty($fields)) {
            if (!is_array($fields)) {
                $fields = ArrayHelper::stringCommasToArray($fields);
            }
            foreach ($titles as $k => $v) {
                if (!in_array($k, $fields)) {
                    unset($titles[$k]);
                }
            }
        }
        return $titles;
    }

    public function __getTitles(array|string|null $fields = null): array
    {
        $allTitles = $this->__getAllTitles($fields);
        $ret = [];
        foreach ($allTitles as $field => $titles) {
            $value = $this->{$field . ":db"};
            if (isset($titles[$value])) {
                $ret[$field] = $titles[$value];
            }
        }
        return $ret;
    }

    public function __getIdentifiers(array|string|null $fields = null): array
    {
        $ret = [];
        if ($this instanceof StringableInterface) {
            $strings = array_keys($this::strings());

            if (!empty($fields)) {
                if (!is_array($fields)) {
                    $fields = ArrayHelper::stringCommasToArray($fields);
                }
            } else {
                $fields = $this->getFields();
            }

            $fields = array_filter($fields, function ($field) use ($strings) {
                return in_array($field, $strings);
            });

            foreach ($fields as $field) {
                $value = $this->{$field};
                if (is_scalar($value)) {
                    $ret[$field] = $value;
                }
            }
        }
        return $ret;
    }

    public function getProps(): array
    {
        return array_merge($this->getFields(), $this->getRelationAliases());
    }

    /**
     * @throws ModelException
     */
    public function getInitialProps(): stdClass
    {
        $fields = $this->getFields();
        $relations = $this->getRelationAliases();
        $data = [];
        foreach ($fields as $f) {
            $data[$f] = "";
        }
        foreach ($relations as $r) {
            $data[$r] = [];
        }
        $M = new static($data);
        return $M->toObject(':c');
    }
}
