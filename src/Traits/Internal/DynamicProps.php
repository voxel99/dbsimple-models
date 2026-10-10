<?php

namespace Jam\Models\Traits\Internal;

use Closure;
use Jam\Models\Cast\CastClosure;
use Jam\Models\Cast\JsonFieldTypes;
use Jam\Models\ModelList;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\ModelException;
use Jam\Models\Utils\CamelCase;
use stdClass;

trait DynamicProps
{
    /**
     * Поля модели в виде массива (внутренее представление)
     * @var array
     */
    private array $jamFields = [];
    /**
     * Защищённые поля модели (внутреннее представление)
     * @var array
     */
    private array $jamGuarded = [];
    /**
     * Скрытые поля модели (не выводятся в toArray
     * @var array
     */
    private array $jamHidden = [];

    /**
     * Вычислимые поля модели (внутреннее представление)
     * @var array
     */
    private array $jamComputed = [];
    private array $jamExplicit = [];

    /**
     * Вычислимые поля, которые должны быть сохранены или обновлены в БД
     * @var array
     */
    private array $jamUpdatable = [];

    /**
     * Дополнительные поля модели (внутреннее представление)
     * Которые могут заполняться и читаться, но которые не попадут в БД
     * @var array
     */
    private array $jamExtra = [];

    /**
     * Служебные поля модели: разрешены на входе, но не сохраняются в данных модели.
     * @var array
     */
    private array $jamService = [];

    /**
     * Флаг охранника защищённых полей
     * Иногда мы его усыпляем, чтобы изменить значение защищённого поля внутри
     * внутренних функций
     * @var bool
     */
    private bool $jamGuardSleep = false;

    /**
     * Методы класса для сохранения get_class_methods($this)
     * @var array
     */
    private array $jamClassMethods = [];
    /**
     * @var array<class-string, array<int, string>>
     */
    private static array $jamClassMethodsCache = [];

    /**
     * Данные (правила) для конвертации значений полей из String
     * @var array
     */
    private array $jamCast = [];
    /**
     * @var array<class-string, array<string, object>>
     */
    private static array $jamCastClassCache = [];
    /**
     * @var array<class-string, array<string, array<int, string>>>
     */
    private static array $jamJsonTopLevelKeyRulesCache = [];
    /**
     * @var array<class-string, array{0: array<int, mixed>, 1: array<string, true>|null}>
     */
    private static array $jamFieldIndexCache = [];
    /**
     * @var array<class-string, bool>
     */
    private static array $jamHasChangeThis = [];
    /**
     * Правила JSON-ключей 1 уровня (см. jsonTopLevelKeyRules()) и описание кастов, из которого они получены
     * @var array<string, array<int, string>>
     */
    private array $jamJsonRules = [];
    private array $jamJsonRulesCast = [];

    /**
     * Динамические данные модели для сохранения в БД
     * @var array
     */
    protected array $jamDataDb = [];

    /**
     * Внешнее представление для данных (это данные $jamDataDb, прошедшие преобразование из $jamCast)
     * @var array
     */
    protected array $jamDataEx = [];

    public function getDataDB(): array
    {
        return $this->jamDataDb;
    }

    public function getDataEX(): array
    {
        return $this->jamDataEx;
    }

    protected string $service = '';

    /**
     * @return array<int, string>
     */
    private function __getClassMethods(): array
    {
        if (!$this->jamClassMethods) {
            $className = get_class($this);
            if (isset(self::$jamClassMethodsCache[$className])) {
                $this->jamClassMethods = self::$jamClassMethodsCache[$className];
            } else {
                $this->jamClassMethods = get_class_methods($this);
                self::$jamClassMethodsCache[$className] = $this->jamClassMethods;
            }
        }
        return $this->jamClassMethods;
    }

    public function __isset($prop)
    {
        if (strpos($prop, ":")) {
            list($prop,) = ArrayHelper::stringCommasToArray($prop, ":");
        }
        return isset($this->jamDataEx[$prop]) || isset($this->jamDataDb[$prop]);
    }

    public function __unset($prop)
    {
        if (strpos($prop, ":")) {
            list($prop,) = ArrayHelper::stringCommasToArray($prop, ":");
        }
        unset($this->jamDataDb[$prop]);
        unset($this->jamDataEx[$prop]);
    }

    private function castTo($prop, $value, $castEx)
    {
        $retValue = $value;
        // Особо обработаем NULL-значения
        if ($castEx && ($value === static::NULL_VALUE)) {
            $retValue = null;
        } elseif (!$castEx && ($value === null)) {
            $retValue = static::NULL_VALUE;
        } else { // Обработка на основе cast-данных
            $cast = $this->getCast();
            if (!empty($cast[$prop])) {
                $closureClass = CastClosure::sharedForField(static::class, $prop, $cast[$prop]);
                if (!$closureClass) {
                    // Для внешнего представления делаем преобразование
                    if ($castEx) {
                        $retValue = CastClosure::castScalar($cast[$prop]->type, $value, $cast[$prop]->is_null ?? false);
                    }
                } else {
                    $checkValue = $closureClass->checkValue($value);
                    if (!$castEx) {
                        $retValue = $checkValue === CastClosure::CAST_DB
                            ? $value
                            : $closureClass->dbValue($value);
                    } elseif ($castEx) {
                        $retValue = $checkValue === CastClosure::CAST_EXTERNAL
                            ? $value
                            : $closureClass->externalValue($value);
                    }
                }
            }
        }
        return $retValue;
    }

    private function castDbToExternal($prop, $value)
    {
        return $this->castTo($prop, $value, true);
    }

    private function castExternalToDb($prop, $value)
    {
        return $this->castTo($prop, $value, false);
    }

    private function readComputedOrExplicitValue(string $prop, array $updatable)
    {
        if (!isset($this->jamDataEx[$prop])) {
            if (!empty($updatable) && in_array($prop, $updatable) && isset($this->jamDataDb[$prop])) {
                $this->jamDataEx[$prop] = $this->castDbToExternal($prop, $this->jamDataDb[$prop]);
            } else {
                $this->jamDataEx[$prop] = $this->{$prop}();
            }
        }

        return $this->jamDataEx[$prop];
    }

    private function hasStoredDynamicValue(string $prop): bool
    {
        return isset($this->jamDataEx[$prop]) || isset($this->jamDataDb[$prop]);
    }

    private function readStoredDynamicValue(string $prop, $dataLayer)
    {
        if ($dataLayer === CastClosure::CAST_DB) {
            if (!isset($this->jamDataDb[$prop])) {
                $this->jamDataDb[$prop] = $this->castExternalToDb($prop, $this->jamDataEx[$prop]);
            }

            return $this->jamDataDb[$prop];
        }

        if (!isset($this->jamDataEx[$prop])) {
            $this->jamDataEx[$prop] = $this->castDbToExternal($prop, $this->jamDataDb[$prop]);
        }

        return $this->jamDataEx[$prop];
    }

    public function __get($prop)
    {
        // Шаги чтения записаны в теле метода (горячий путь toArray и прикладного кода);
        // публичные геттеры описаний вызываются, как и раньше.
        $dataLayer = false;
        if (strpos($prop, ":")) {
            [$prop, $dataLayer] = ArrayHelper::stringCommasToArray($prop, ":");
        }
        $explicit = $this->getExplicit();
        $computed = $this->getComputed();
        $extra = $this->getExtra();
        $updateble = $this->getUpdatable();

        if (($computed && in_array($prop, $computed)) || ($explicit && in_array($prop, $explicit))) {
            return $this->readComputedOrExplicitValue($prop, $updateble);
        }

        if ($extra && in_array($prop, $extra)) {
            if (isset($this->jamDataEx[$prop])) {
                return $this->jamDataEx[$prop];
            }

            return null;
        }

        if ($dataLayer === CastClosure::CAST_DB) {
            if (isset($this->jamDataDb[$prop])) {
                return $this->jamDataDb[$prop];
            }
            if (isset($this->jamDataEx[$prop])) {
                return $this->jamDataDb[$prop] = $this->castExternalToDb($prop, $this->jamDataEx[$prop]);
            }
            return null;
        }

        if (isset($this->jamDataEx[$prop])) {
            return $this->jamDataEx[$prop];
        }
        if (isset($this->jamDataDb[$prop])) {
            return $this->jamDataEx[$prop] = $this->castDbToExternal($prop, $this->jamDataDb[$prop]);
        }

        return null;
    }

    /**
     * in_array($prop, $fields) через индекс [поле => true], общий для класса. Список полей
     * экземпляра обычно тот же массив, что и у остальных экземпляров класса (сравнение
     * идентичных массивов — проверка указателя). Индекс строится, только если все поля —
     * нечисловые строки: для них isset() совпадает с нестрогим in_array().
     */
    private function isKnownField(string $prop, array $fields): bool
    {
        $entry = self::$jamFieldIndexCache[static::class] ?? null;
        if ($entry === null || $entry[0] !== $fields) {
            $index = [];
            foreach ($fields as $field) {
                if (!is_string($field) || is_numeric($field)) {
                    $index = null;
                    break;
                }
                $index[$field] = true;
            }
            $entry = self::$jamFieldIndexCache[static::class] = [$fields, $index];
        }
        if ($entry[1] === null || is_numeric($prop)) {
            return in_array($prop, $fields);
        }
        return isset($entry[1][$prop]);
    }

    private function throwIncorrectDynamicSetProp(string $prop, array $fields): never
    {
        $map = $this->getRelationClasses();
        $extra = $this->getExtra();
        throw new ModelException("[" . $prop . "] is not a correct field of class " . get_class($this)
            . ". Fill your \$field property: " . implode(", ", $fields)
            . ($map ? ".\nOr fill your relations properly: " . implode(", ", array_keys($map)) : '')
            . ".\nOr fill \$extra property" . ($extra ? ': ' . implode(", ", $extra) : ''));
    }

    private function jsonTopLevelKeyMatches(string $prop, string $rule): bool
    {
        if (str_ends_with($rule, '*')) {
            return str_starts_with($prop, substr($rule, 0, -1));
        }

        return $prop === $rule;
    }

    /**
     * @param array<string, array<int, string>> $jsonRules см. jsonTopLevelKeyRules()
     */
    private function jsonFieldForTopLevelKey(string $prop, array $jsonRules): ?string
    {
        foreach ($jsonRules as $field => $rules) {
            foreach ($rules as $rule) {
                if ($this->jsonTopLevelKeyMatches($prop, $rule)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * JSON-поля с перечнем ключей 1 уровня: [поле => правила]. Зависит только от описания
     * кастов, а оно общее для класса (см. getCast()), поэтому разбирается один раз на класс,
     * а не перебором всех кастов на каждое присваивание.
     *
     * @return array<string, array<int, string>>
     */
    private function jsonTopLevelKeyRules(array $cast): array
    {
        if (!$cast) {
            return [];
        }
        $className = get_class($this);
        // Кеш действителен, пока экземпляр работает с тем же описанием кастов, что и класс
        if (isset(self::$jamJsonTopLevelKeyRulesCache[$className]) && $cast === (self::$jamCastClassCache[$className] ?? null)) {
            return self::$jamJsonTopLevelKeyRulesCache[$className];
        }
        $rules = [];
        foreach ($cast as $field => $castItem) {
            if (($castItem->type ?? null) !== CastClosure::TYPE_JSON || empty($castItem->top_level_keys)) {
                continue;
            }
            $rules[$field] = $castItem->top_level_keys;
        }
        if ($cast === (self::$jamCastClassCache[$className] ?? null)) {
            self::$jamJsonTopLevelKeyRulesCache[$className] = $rules;
        }
        return $rules;
    }

    private function storeJsonTopLevelKeyValue(string $jsonField, string $prop, $value): void
    {
        $payload = $this->hasStoredDynamicValue($jsonField)
            ? $this->readStoredDynamicValue($jsonField, CastClosure::CAST_EXTERNAL)
            : null;

        if (is_array($payload)) {
            $payload[$prop] = $value;
        } else {
            if (!is_object($payload)) {
                $payload = new stdClass();
            }
            $payload->{$prop} = $value;
        }

        $this->storeDynamicAssignedValue($jsonField, $payload, CastClosure::CAST_EXTERNAL);
        $this->forgetComputedValues();
        $this->callChangeThisMethod($jsonField);
    }

    /**
     * Массив/объект, присвоенный свойству-отношению (или @pivot), разворачивается в модель.
     * Класс определяется так же, как в getRelationClasses(), но без построения замыканий
     * по всем отношениям на каждое присваивание.
     */
    private function normalizeRelationAssignedValue(string $prop, $value)
    {
        if (!(($value instanceof stdClass) || is_array($value))) {
            return $value;
        }

        if ($prop === static::PIVOT_FIELD && $this->getPivotClass()) {
            $className = $this->getPivotClass();
        } else {
            $R = $this->getRelationByAlias($prop);
            if (!$R) {
                return $value;
            }
            $className = $R->getClass($this);
        }
        if (!$className) {
            // Polymorphic relations may legitimately have no target class for some types,
            // for example PostAttach(upload_type=text|toc). In that case drop raw payload.
            return null;
        }

        if (!empty($this->hasMany[$prop])) {
            return new ModelList($className, $value);
        }

        return new $className($value);
    }

    private function storeDynamicAssignedValue(string $prop, $value, $dataLayer): void
    {
        if ($dataLayer === CastClosure::CAST_EXTERNAL) {
            $this->jamDataEx[$prop] = $value;
            unset($this->jamDataDb[$prop]);
            return;
        }

        $this->jamDataDb[$prop] = $value;
        unset($this->jamDataEx[$prop]);
    }

    /**
     * Шаги присваивания записаны в теле метода, а не отдельными приватными методами:
     * это самый горячий путь гидрации (каждое поле каждой вложенной модели), и вызовы
     * мелких хелперов составляли заметную часть его стоимости. Публичные геттеры описаний
     * (getFields/getComputed/...) по-прежнему вызываются — модель может их переопределить.
     */
    public function __set($prop, $value)
    {
        $dataLayer = false;
        if (strpos($prop, ":")) {
            [$prop, $dataLayer] = ArrayHelper::stringCommasToArray($prop, ":");
        }
        $fields = $this->getFields();

        $explicit = $this->getExplicit();
        if ($explicit && in_array($prop, $explicit)) {
            $this->jamDataEx[$prop] = $value;
            return;
        }

        $extra = $this->getExtra();
        if ($extra && in_array($prop, $extra)) {
            $this->jamDataEx[$prop] = $value;
            return;
        }

        // Игнорируем присваивание вычислимых свойств,
        // если модель явно не разрешила запись через extra/explicit/updatable.
        $computed = $this->getComputed();
        $updatable = $this->getUpdatable();
        if ($computed && in_array($prop, $computed) && !in_array($prop, $updatable)) {
            return;
        }

        $cast = $this->getCast();
        if ($cast !== $this->jamJsonRulesCast) {
            $this->jamJsonRules = $this->jsonTopLevelKeyRules($cast);
            $this->jamJsonRulesCast = $cast;
        }
        if ($this->jamJsonRules) {
            $jsonField = $this->jsonFieldForTopLevelKey($prop, $this->jamJsonRules);
            if ($jsonField !== null) {
                $this->storeJsonTopLevelKeyValue($jsonField, $prop, $value);
                return;
            }
        }

        $service = $this->getService();
        if (
            !(empty($fields)
            || $this->isKnownField($prop, $fields)
            || in_array($prop, $service)
            || $this->isRelationProp($prop)
            || ($prop === static::PIVOT_FIELD)
            || ($prop === static::TITLES_FIELD)
            || ($prop === static::IDENTIFIERS_FIELD))
        ) {
            $this->throwIncorrectDynamicSetProp($prop, $fields);
        }
        if (in_array($prop, $service) && $prop !== static::SKIP_UPDATE) {
            return;
        }

        if (!$this->jamGuardSleep && in_array($prop, $this->getGuarded())) {
            throw new ModelException($prop . " is guarded in class " . get_class($this));
        }

        if (!$dataLayer) {
            // Слой данных по значению: NULL_VALUE — db, массив/объект — внешний, иначе решает каст
            $dataLayer = CastClosure::CAST_DB;
            if ($value === static::NULL_VALUE) {
                // CAST_DB
            } elseif (is_array($value) || is_object($value)) {
                $dataLayer = CastClosure::CAST_EXTERNAL;
            } elseif (!empty($cast[$prop])) {
                $closureClass = CastClosure::sharedForField(static::class, $prop, $cast[$prop]);
                if ($closureClass) {
                    $layerCheck = $closureClass->checkValue($value);
                    if ($layerCheck) {
                        $dataLayer = $layerCheck;
                    }
                }
            }
        }

        $changeMethod = $this->findChangePropertyMethod($prop, $value, $dataLayer);
        if ($changeMethod) {
            $value = call_user_func($changeMethod);
        }
        if (($value instanceof stdClass) || is_array($value)) {
            $value = $this->normalizeRelationAssignedValue($prop, $value);
        }

        if ($dataLayer === CastClosure::CAST_EXTERNAL) {
            $this->jamDataEx[$prop] = $value;
            unset($this->jamDataDb[$prop]);
        } else {
            $this->jamDataDb[$prop] = $value;
            unset($this->jamDataEx[$prop]);
        }
        if ($this->jamDataEx) {
            $this->forgetComputedValues();
        }
        $this->callChangeThisMethod($prop);
    }

    /**
     * Сбрасывает закэшированные значения вычислимых полей: после изменения данных модели
     * $model->computed_prop должен пересчитываться, а не возвращать устаревшее значение.
     * Updatable- и explicit-поля не трогаем — их значения могут быть присвоены явно.
     */
    private function forgetComputedValues(): void
    {
        $computed = $this->getComputed();
        if (!$computed || !$this->jamDataEx) {
            return;
        }
        // То же, что unset по array_diff($computed, updatable, explicit), но без построения
        // разности на каждое присваивание: обычно в jamDataEx нет закэшированных computed.
        foreach ($computed as $prop) {
            if (
                array_key_exists($prop, $this->jamDataEx)
                && !in_array($prop, $this->getUpdatable())
                && !in_array($prop, $this->getExplicit())
            ) {
                unset($this->jamDataEx[$prop]);
            }
        }
    }

    /**
     * Cписок полей модели
     *
     * @return array<int, string> Model field names
     */
    public function getFields()
    {
        return $this->jamFields ?: ($this->fields === '' ? [] : $this->lazyFieldList($this->jamFields, $this->fields));
    }

    /**
     * Cписок охраняемых полей модели, в которые нельзя сделать запись вне внутренних методов (change* прежде всего)
     *
     * @return array<int, string> Guarded field names
     */
    public function getGuarded()
    {
        return $this->jamGuarded ?: ($this->guarded === '' ? [] : $this->lazyFieldList($this->jamGuarded, $this->guarded));
    }

    public function getHidden()
    {
        return $this->jamHidden ?: ($this->hidden === '' ? [] : $this->lazyFieldList($this->jamHidden, $this->hidden));
    }

    public function getComputed()
    {
        return $this->jamComputed ?: ($this->computed === '' ? [] : $this->lazyFieldList($this->jamComputed, $this->computed));
    }

    public function getExplicit(): array
    {
        return $this->jamExplicit ?: ($this->explicit === '' ? [] : $this->lazyFieldList($this->jamExplicit, $this->explicit));
    }

    public function getExtra()
    {
        return $this->jamExtra ?: ($this->extra === '' ? [] : $this->lazyFieldList($this->jamExtra, $this->extra));
    }

    public function getService()
    {
        if (!$this->jamService) {
            $this->jamService = array_values(array_unique(array_merge(
                [static::SKIP_UPDATE],
                ArrayHelper::stringCommasToArray($this->service)
            )));
        }
        return $this->jamService;
    }

    /**
     * Список полей из строки-свойства модели ('id, name'), разбирается один раз.
     * @return array<int, string>
     */
    private function lazyFieldList(array &$cache, string $source): array
    {
        // Пустое описание (у большинства моделей нет computed/explicit/extra/...) не кешируется
        // в пустом массиве, поэтому раньше разбиралось заново на каждом вызове.
        if (!$cache && $source !== '') {
            $cache = ArrayHelper::stringCommasToArray($source);
        }
        return $cache;
    }

    public function isField($field)
    {
        return in_array($field, $this->getFields());
    }

    public function isGuarded($field)
    {
        return in_array($field, $this->getGuarded());
    }

    public function isHidden($field)
    {
        return in_array($field, $this->getHidden());
    }

    public function isComputed($field)
    {
        return in_array($field, $this->getComputed());
    }

    public function isExtra($field)
    {
        return in_array($field, $this->getExtra());
    }

    public function getUpdatable()
    {
        return $this->jamUpdatable ?: ($this->updatable === '' ? [] : $this->lazyFieldList($this->jamUpdatable, $this->updatable));
    }

    public function getType($field)
    {
        $cast = $this->getCast();
        return !empty($cast[$field]->type) ? $cast[$field]->type : 'text';
    }

    /**
     * @return array<int|string, mixed>
     */
    private function normalizeCastFields($fields): array
    {
        if (is_array($fields)) {
            return $fields;
        }

        if (!str_contains($fields, '(')) {
            return ArrayHelper::stringCommasToArray($fields);
        }

        return array_values(array_filter(
            array_map('trim', ArrayHelper::stringCommasToArrayCheckBraces($fields)),
            fn($field) => $field !== ''
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCastDefinition(string $type, $rule): array
    {
        if ($type === "null") {
            return [
                'is_null' => true
            ];
        }

        return [
            'type' => $type,
            'rule' => $rule
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function mergeCastDefinition(string $field, array $definition): object
    {
        if (!empty($this->jamCast[$field])) {
            $definition = array_merge((array) $this->jamCast[$field], $definition);
        }

        return (object) $definition;
    }

    private function assignNamedCastDefinition(string $type, string $field, $rule): void
    {
        $this->jamCast[$field] = $this->mergeCastDefinition($field, $this->buildCastDefinition($type, $rule));
    }

    private function assignIndexedCastDefinition(string $type, string $field): void
    {
        if ($type === CastClosure::TYPE_JSON && preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\((.*)\)$/', $field, $matches)) {
            $definition = $this->buildCastDefinition($type, null);
            [$topLevelKeys, $topLevelKeyTypes] = $this->parseJsonTopLevelKeys($matches[2]);
            $definition['top_level_keys'] = $topLevelKeys;
            if ($topLevelKeyTypes) {
                $definition['top_level_key_types'] = $topLevelKeyTypes;
            }
            $this->jamCast[$matches[1]] = $this->mergeCastDefinition($matches[1], $definition);
            return;
        }

        $this->jamCast[$field] = $this->mergeCastDefinition($field, $this->buildCastDefinition($type, null));
    }

    /**
     * Разбирает перечень полей 1 уровня JSON-колонки. Поддерживает аннотацию типа под-поля
     * через двоеточие: `flags: flags`. Возвращает [список имён, карта имя=>тип].
     *
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    private function parseJsonTopLevelKeys(string $keysStr): array
    {
        $keys = [];
        $types = [];
        foreach (ArrayHelper::stringCommasToArray($keysStr) as $entry) {
            if (strpos($entry, ':') === false) {
                $keys[] = $entry;
                continue;
            }

            [$name, $subType] = array_map('trim', explode(':', $entry, 2));
            if ($name === '') {
                continue;
            }
            if (!JsonFieldTypes::isKnown($subType)) {
                throw new ModelException(sprintf(
                    "Unknown JSON field type [%s] for key [%s] in class %s",
                    $subType,
                    $name,
                    get_class($this)
                ));
            }
            $keys[] = $name;
            $types[$name] = $subType;
        }

        return [$keys, $types];
    }

    private function hydrateCastDefinitions(): void
    {
        foreach ($this->types as $type => $fields) {
            foreach ($this->normalizeCastFields($fields) as $k => $f) {
                // Поле задано в виде [fieldName => fieldRule]
                if (!is_numeric($k)) {
                    $this->assignNamedCastDefinition($type, $k, $f);
                } else {
                    $this->assignIndexedCastDefinition($type, $f);
                }
            }
        }
    }

    public function getCast($debug = false)
    {
        if (!$this->jamCast && !empty($this->types)) {
            $className = get_class($this);
            if (isset(self::$jamCastClassCache[$className])) {
                $this->jamCast = self::$jamCastClassCache[$className];
            } else {
                $this->hydrateCastDefinitions();
                self::$jamCastClassCache[$className] = $this->jamCast;
            }
        }
        return $this->jamCast;
    }

    /**
     * Ищет метод "change*" который совпадает с изменяемым свойством
     * Допускается вложенность элементов друг в друга и управление изменением из родителя.
     * Например, изменением по такой цепочке $obj->nested->prop можно управлять
     * из $obj->changeNestedProp(old_val, new_val)
     *
     * @param string $prop Имя свойства
     * @param mixed $value Новое значение
     *
     * @return Closure|null
     */
    public function findChangePropertyMethod($prop, $value, $dataLayer)
    {
        $return_method_name = null;
        // Если есть метод change{$Prop}, вызываем его с параметром $value
        $methods = $this->__getClassMethods();
        $method_name = "change" . CamelCase::to($prop);
        if (in_array($method_name, $methods)) {
            $return_method_name = function () use ($method_name, $prop, $value, $dataLayer) {
                // Читаем предыдущее значение свойства
                $oldValue = $dataLayer === CastClosure::CAST_EXTERNAL
                    ? (isset($this->jamDataEx[$prop]) ? $this->jamDataEx[$prop] : null)
                    : (isset($this->jamDataDb[$prop]) ? $this->jamDataDb[$prop] : null);
                // Внутри метода changeProp можно обращаться и устанавливать защищённые переменные
                $guard_is_sleep = $this->jamGuardSleep;
                // Усыпляем охранника
                if (!$this->jamGuardSleep) {
                    $this->jamGuardSleep = true;
                }
                // Вызываем метод
                $return_value = $this->{$method_name}($value, $oldValue);
                // Если забыли вернуть новое значение, то ошибка
                if (($return_value === null) && ($value !== null)) {
                    throw new ModelException(sprintf("%s::%s must return a value", get_class($this), $method_name));
                }
                // Будим охранника, если усыпили ранее перед вызовом метода
                if (!$guard_is_sleep) {
                    $this->jamGuardSleep = false;
                }
                return $return_value;
            };
        }
        return $return_method_name;
    }

    /**
     * Ищет метод "changeThis"
     *
     * @param string $prop Имя свойства
     * @return bool
     */
    public function callChangeThisMethod($prop)
    {
        if (self::$jamHasChangeThis[static::class] ??= method_exists($this, 'changeThis')) {
            $this->changeThis($prop);
            return true;
        }
        return false;
    }

    /**
     * Ограничить поля модели
     *
     * @param array|string $fields Поля модели
     * @param bool $silence
     * @return $this
     * @throws ModelException
     */
    public function cutFields(array|string $fields, $silence = false)
    {
        if (!is_array($fields)) {
            $fields = ArrayHelper::stringCommasToArray($fields);
        }
        $oldFields = $this->getFields();
        $computedFields = $this->getComputed();
        if (!$silence) {
            foreach ($fields as $field) {
                if (!in_array($field, $oldFields) && !in_array($field, $computedFields)) {
                    throw new ModelException(sprintf("Field `%s` not exists in model %s", $field, get_class($this)));
                }
            }
        }
        if ($computedFields) {
            $fields = array_diff($fields, $computedFields);
        }
        $this->jamFields = $fields;
        $diffDataDb = array_diff(array_keys($this->jamDataDb), $fields);
        $diffDataEx = array_diff(array_keys($this->jamDataEx), $fields);
        foreach ($diffDataDb as $key) {
            unset($this->jamDataDb[$key]);
        }
        foreach ($diffDataEx as $key) {
            unset($this->jamDataEx[$key]);
        }
        return $this;
    }

    public function excludeFields($excludes)
    {
        if (!is_array($excludes)) {
            $excludes = ArrayHelper::stringCommasToArray($excludes);
        }
        // getFields(), а не $this->jamFields: список полей инициализируется лениво
        $this->jamFields = array_values(array_diff($this->getFields(), $excludes));
        foreach ($excludes as $excludeField) {
            if (isset($this->jamDataDb[$excludeField])) {
                unset($this->jamDataDb[$excludeField]);
            }
            if (isset($this->jamDataEx[$excludeField])) {
                unset($this->jamDataEx[$excludeField]);
            }
        }
        return $this;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, string> $fields
     * @return array<string, mixed> Item with only specified fields
     */
    public function cutItem(array $item, array $fields)
    {
        if ($fields && !empty($item)) {
            $relations = array_keys($this->getAllRelations());
            foreach ($item as $field => $value) {
                if (!in_array($field, $fields) && !in_array($field, $relations)) {
                    unset($item[$field]);
                }
            }
        }
        return $item;
    }
}
