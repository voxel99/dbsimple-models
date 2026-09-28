<?php

namespace Jam\Models\Traits;

use Exception;
use Jam\Models\Model;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Utils\Code;
use stdClass;

trait Stringable
{
    protected string $thisClassStringable = '';

    public static array $stringsFlipped = [];
    public static array $stringsTypes = [];

    public function bootStringable(): void
    {
        $this->thisClassStringable = get_class($this);
    }

    /**
     * Скрывать ли по умолчанию значения с отрицательными ключами (служебные значения справочника).
     * Переопределите в модели, если видимость зависит от контекста (например, роли текущего пользователя).
     */
    protected static function positiveKeysOnlyByDefault(): bool
    {
        return false;
    }

    private static function resolvePositiveKeysOnly(?bool $positiveKeysOnly): bool
    {
        return $positiveKeysOnly ?? static::positiveKeysOnlyByDefault();
    }

    public static function stringsType($type, $positiveKeysOnly = null)
    {
        $positiveKeysOnly = self::resolvePositiveKeysOnly($positiveKeysOnly);
        $key = self::getKey($type, $positiveKeysOnly);
        if (!isset(static::$stringsTypes[$key])) {
            $strings = static::strings();
            if (!isset($strings[$type])) {
                throw new Exception(sprintf("Wrong type in stringsType: %s", $type));
            }
            $ret = $strings[$type];
            if ($positiveKeysOnly) {
                $ret = ArrayHelper::positiveKeys($ret);
            }
            static::$stringsTypes[$key] = $ret;
        }
        return static::$stringsTypes[$key];
    }

    private static function getKey($type, bool $positiveKeysOnly): string
    {
        return get_called_class() . ' ' . $type . ' ' . ($positiveKeysOnly ? 'P' : 'N');
    }

    public static function stringsFlipped($type, $positiveKeysOnly = null)
    {
        $positiveKeysOnly = self::resolvePositiveKeysOnly($positiveKeysOnly);
        $key = self::getKey($type, $positiveKeysOnly);
        if (!isset(static::$stringsFlipped[$key])) {
            static::$stringsFlipped[$key] = array_flip(static::stringsType($type, $positiveKeysOnly));
        }
        return static::$stringsFlipped[$key];
    }

    public static function toString($type, $value)
    {
        $stringsType = static::stringsType($type);
        if (!isset($stringsType[$value])) {
            throw new Exception(sprintf(
                "Strings type '%s' for value '%s' not found. All values: [%s]",
                $type,
                $value,
                ArrayHelper::toDescription($stringsType)
            ));
        }
        return $stringsType[$value];
    }

    public static function stringValueIfExists($type, $value)
    {
        $strings = static::strings();
        if (isset($strings[$type])) {
            return static::toString($type, $value);
        }
        return $value;
    }

    public static function stringValue($type, $value)
    {
        if (!is_string($value)) {
            $value = static::toString($type, $value);
        }
        return $value;
    }

    public static function intValue($type, $value, $default = null)
    {
        $stringValue = static::stringValue($type, $value);
        return static::fromString($type, $stringValue, $default);
    }

    public static function fromString($type, $value, $default = null)
    {
        if ($value === null) {
            return $default;
        }
        $flippedTypeStrings = static::stringsFlipped($type);
        return $flippedTypeStrings[$value] ?? $default;
    }

    public static function isString($type, $stringValue, $intValue): bool
    {
        $flippedStrings = static::stringsFlipped($type, false);
        return $flippedStrings[$stringValue] === $intValue;
    }

    public static function hasString($type, $string, $positiveKeysOnly = null): bool
    {
        $strings = static::strings();
        $stat = false;
        if (isset($strings[$type])) {
            $stringsFlipped = static::stringsFlipped($type, $positiveKeysOnly);
            $stat = isset($stringsFlipped[$string]);
        }
        return $stat;
    }

    protected static function getDescriptions($descriptionMethod)
    {
        if (is_string($descriptionMethod)) {
            $descriptions = static::$descriptionMethod();
        } elseif (is_array($descriptionMethod)) {
            $descriptions = $descriptionMethod;
        } else {
            throw new Exception(sprintf('Wrong stringsDescription method: %s', json_encode($descriptionMethod)));
        }
        return $descriptions;
    }

    protected static function modelInstance(): ?Model
    {
        if (!is_callable([static::class, 'instance'])) {
            return null;
        }

        /** @var callable(): mixed $factory */
        $factory = [static::class, 'instance'];
        $model = $factory();
        return $model instanceof Model ? $model : null;
    }

    /**
     * Возвращает массив описаний string => description
     */
    public static function stringsDescription($type, $descriptionMethod = "", $positiveKeysOnly = null): array
    {
        if (!$descriptionMethod) {
            $model = static::modelInstance();
            if ($model) {
                $descriptionMethod = $model->__getTitleMethod($type);
            } else {
                $descriptionMethod = Code::getDescriptionByField($type);
            }
        }
        $descriptions = static::getDescriptions($descriptionMethod);
        $strings = static::stringsType($type, $positiveKeysOnly);
        $ret = [];
        foreach ($strings as $intVal => $strVal) {
            $strDesc = $descriptions[$intVal] ?? '';
            $ret[$strVal] = $strDesc;
        }
        return $ret;
    }

    public static function stringsDescriptionLine($type, $descriptionMethod = "", $positiveKeysOnly = null): string
    {
        $strDescriptions = static::stringsDescription($type, $descriptionMethod, $positiveKeysOnly);
        return ArrayHelper::toDescription($strDescriptions);
    }

    /**
     * Сделать преобразование строк в объекте или массиве
     */
    public static function stringify($data): array|stdClass
    {
        $model = static::modelInstance();
        if (!$model) {
            throw new Exception(sprintf('%s::stringify requires %s descendant', static::class, Model::class));
        }

        $isObject = is_object($data);
        $arrayData = $isObject ? ArrayHelper::convertFromObject($data) : $data;
        $model->fromArray($arrayData, true);
        $arrayConverted = $model->toArray([
            Model::FLAG_DEPENDENCIES => true,
            Model::FLAG_COMPUTABLE => false,
            Model::FLAG_STRINGABLE => true
        ]);
        $arrayReturn = ArrayHelper::mergeDeep($arrayData, $arrayConverted);
        return $isObject
            ? ArrayHelper::convertToObject($arrayReturn)
            : $arrayReturn;
    }

    /**
     * Сделать преобразование строк в объекте или массиве
     */
    public static function destringify($data)
    {
        $isObject = is_object($data);
        $arrayData = $isObject ? ArrayHelper::convertFromObject($data) : $data;
        if (in_array(Stringable::class, class_uses(static::class))) {
            foreach ($arrayData as $k => $v) {
                if (is_string($v) && self::hasString($k, $v)) {
                    $arrayData[$k] = self::fromString($k, $v);
                }
            }
        }
        return $isObject
            ? ArrayHelper::convertToObject($arrayData)
            : $arrayData;
    }
}
