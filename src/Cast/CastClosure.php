<?php

namespace Jam\Models\Cast;

use Exception;
use Jam\Models\Model;

abstract class CastClosure
{
    public const TYPE_INT = 'int';
    public const TYPE_UINT = 'uint';
    public const TYPE_FLOAT = 'float';
    public const TYPE_STRING = 'string';
    public const TYPE_DATE = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_BOOL = 'bool';
    public const TYPE_FLAGS = 'flags';
    public const TYPE_JSON = 'json';
    public const TYPE_CLOSURE = 'closure';
    public const TYPE_IP = 'ip';
    public const TYPE_DELIM = 'delim';

    public const CAST_DB = 'db';
    public const CAST_EXTERNAL = 'ex';

    abstract public function dbValue($value);

    abstract public function externalValue($value);

    public function stringValue($value)
    {
        return $this->checkValue($value) === self::CAST_EXTERNAL
            ? $value
            : $this->externalValue($value);
    }

    // Можно переопределить этот, если есть возможность определить внешнее это представление
    // или представление для БД
    // Метод должен возвращать Model::CAST_DB или Model::CAST_EXTERNAL
    public function checkValue($value)
    {
        if (is_array($value) || is_object($value)) {
            return self::CAST_EXTERNAL;
        }
        return null;
    }

    // Внешнее представление - массив, а не объект
    public function isCastToArray()
    {
        return is_array($this->externalValue(null));
    }

    protected $rule = null;

    public static array $factoryClasses = [
        self::TYPE_FLAGS => CastFlags::class,
        self::TYPE_JSON => CastJson::class,
        self::TYPE_IP => CastIp::class,
        self::TYPE_DELIM => CastDelim::class,
        self::TYPE_BOOL => CastBool::class
    ];

    public function __construct($rule = null)
    {
        $this->rule = $rule;
    }



    public static function factory($type, $rule)
    {
        $cls = self::$factoryClasses[$type] ?? null;
        if (!$cls) {
            throw new Exception(sprintf("Wrong cast type: %s", $type));
        }
        return new $cls($rule);
    }

    /**
     * Получить объект CastClosure
     * @param $castObj {type, rule}
     * @return CastClosure|null
     * @throws Exception
     */
    public static function fromCast($castObj)
    {
        $type = $castObj->type;
        $rule = $castObj->rule;
        $castClosureObj = null;
        if (isset(self::$factoryClasses[$type])) {
            $castClosureObj = static::factory($type, $rule);
        } elseif (($type === static::TYPE_CLOSURE) && !is_null($rule)) {
            $castClosureObj = new $rule();
        }
        // Прокидываем объявленные типы под-полей JSON-колонки (например `flags: flags`),
        // чтобы CastJson мог двусторонне преобразовывать значения этих под-полей.
        if ($castClosureObj instanceof CastJson && !empty($castObj->top_level_key_types)) {
            $castClosureObj->setTopLevelKeyTypes((array)$castObj->top_level_key_types);
        }
        return $castClosureObj;
    }

    /**
     * @var array<string, array<string, array{0: object, 1: ?string, 2: ?string, 3: ?CastClosure}>>
     */
    private static array $sharedByField = [];

    /**
     * То же, что fromCast($castObj), но для встроенных типов возвращает один экземпляр на поле класса
     * модели: описания кастов общие для всех экземпляров класса (см. DynamicProps::getCast()),
     * а встроенные касты не меняют состояние после создания. Пользовательские касты (TYPE_CLOSURE)
     * создаются заново на каждый вызов, как и в fromCast().
     *
     * Запись кеша действительна, пока поле описывает тот же объект каста (описание строится
     * из $types один раз на класс и считается неизменяемым) и класс типа в реестре
     * $factoryClasses не поменялся. Память ограничена числом полей с кастами.
     *
     * @internal Для горячих путей пакета. Возвращённый объект нельзя изменять.
     * @param object $castObj {type, rule}
     * @return CastClosure|null
     * @throws Exception
     */
    public static function sharedForField(string $modelClass, string $field, object $castObj): ?CastClosure
    {
        $entry = self::$sharedByField[$modelClass][$field] ?? null;
        if ($entry !== null && $entry[0] === $castObj && (self::$factoryClasses[$entry[1]] ?? null) === $entry[2]) {
            return $entry[3];
        }
        $type = $castObj->type ?? null;
        $closure = self::fromCast($castObj);
        if (is_string($type) && $type !== self::TYPE_CLOSURE) {
            self::$sharedByField[$modelClass][$field] = [$castObj, $type, self::$factoryClasses[$type] ?? null, $closure];
        }
        return $closure;
    }

    public static function castScalar($type, $value, $isNull = false)
    {
        $ret = $value;
        switch ($type) {
            case self::TYPE_INT:
                $ret = (int) $value;
                break;
            case self::TYPE_STRING:
                $ret = (string) $value;
                break;
            case self::TYPE_UINT:
                // Handle large numbers that exceed PHP_INT_MAX
                if (is_numeric($value) && (float) $value > PHP_INT_MAX) {
                    $ret = (string)$value;
                } else {
                    $ret = @sprintf("%u", $value);
                }
                break;
            case self::TYPE_FLOAT:
                $ret = (float)$value;
                break;
            // case self::TYPE_BOOL:
            //    $ret = (bool)$value;
            //    break;
            case self::TYPE_DATE:
                $ret = !$value
                    ? ""
                    : date("Y-m-d", strtotime($value));
                break;
            case self::TYPE_DATETIME:
                $ret = !$value
                    ? ""
                    : date("Y-m-d H:i:s", strtotime($value));
                break;
        }
        if ($isNull && empty($ret)) {
            $ret = null;
        }
        return $ret;
    }
}
