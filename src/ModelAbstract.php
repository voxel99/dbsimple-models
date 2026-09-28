<?php

namespace Jam\Models;

use Jam\Models\Traits\Crud\Withable;
use Jam\Models\Traits\Internal\ConvertFlags;
use Jam\Models\Traits\Internal\DbSimple;
use Jam\Models\Traits\Internal\Observable;
use Jam\Models\Utils\Code;

abstract class ModelAbstract
{
    use DbSimple;
    use Observable;
    use Withable;
    use ConvertFlags;

    public const string NULL_VALUE = '\NULL';
    public const string EXPLICIT = 'explicit';

    public const string FLAG_DEPENDENCIES = 'd';
    public const string FLAG_EMPTY = 'e';
    public const string FLAG_HIDDEN = 'h';
    public const string FLAG_COMPUTABLE = 'c';
    public const string FLAG_STRINGABLE = 's';
    public const string FLAG_ORDER = 'o';
    public const string FLAG_KEYBY = 'k';
    public const string FLAG_LIMIT = 'l';
    public const string FLAG_OFFSET = 'f';
    public const string FLAG_INTERNAL_DATABASE = '_';
    public const string FLAG_EXTRA = 'x';
    public const string FLAG_PIVOT = 'p';
    public const string FLAG_UPDATABLE = 'u';
    public const string FLAG_SCALAR = 'a';
    public const string FLAG_SKIP_NO = 'q';
    public const string FLAG_TITLES = 't';
    public const string FLAG_IDENTIFIERS = 'i';
    public const string FLAG_WITH_TRASHED = 'r';

    private static array $thisModelBootMethods = [];

    public function __construct()
    {

        $thisClass = get_class($this);
        if (!isset(self::$thisModelBootMethods[$thisClass])) {
            self::$thisModelBootMethods[$thisClass] = [];
            $traits = Code::classUsesRecursive($thisClass);
            foreach ($traits as $trait) {
                if (method_exists($thisClass, $method = 'boot' . Code::classBasename($trait))) {
                    self::$thisModelBootMethods[$thisClass][] = $method;
                }
            }
        }
        if (!empty(self::$thisModelBootMethods[$thisClass])) {
            foreach (self::$thisModelBootMethods[$thisClass] as $method) {
                $this->{$method}();
            }
        }
    }

    public static function convertRecordsBeforeSaveToDb(array $rec): array
    {
        foreach ($rec as $field => $value) {
            if ($value === static::NULL_VALUE) {
                $rec[$field] = null;
            } elseif (is_null($value)) {
                unset($rec[$field]);
            }
        }
        return $rec;
    }

    public function setState(array $state): void
    {
        foreach ($state as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            } else {
                throw new ModelException("Property " . $k . " is not exists in Model " . get_class($this));
            }
        }
    }

    /**
     * Передаёт связанной/созданной модели соединение (и, опционально, with).
     * Важно переключать именно через db(): раньше копировалось только имя соединения,
     * а запросы связанной модели продолжали идти в master.
     */
    public function inheritModel(ModelAbstract $M, $inheritWith = false): void
    {
        if (static::hasDbConnection($this->jamConnection)) {
            $M->db($this->jamConnection);
        } else {
            $M->setState(['jamConnection' => $this->jamConnection]);
        }
        if ($inheritWith) {
            $M->setState(['with' => $this->with]);
        }
    }

    /**
     * ModelList does not define relations, but Withable is shared via ModelAbstract.
     *
     * @return array<string, Relation>
     */
    public function getAllRelations(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getExplicit(): array
    {
        return [];
    }

    /**
     * Инициализирует модель из массива
     * @param array $array
     * @return static|void
     */
    abstract public function fromArray(array $array);

    /**
     * Конвертирует модель в массив
     * @param string|array|null $flags
     * @param string|array|null $fields
     * @param string|array|null $with
     * @param string|array|null $exclude
     * @param bool $cutFields
     * @return array
     */
    abstract public function toArray($flags = null, $fields = null, $with = null, $exclude = null, $cutFields = false): array;

    /**
     * Конвертирует модель в объект
     * @param string|array|null $flags
     * @param string|array|null $fields
     * @param string|array|null $with
     * @param string|array|null $exclude
     * @return array|object
     */
    abstract public function toObject($flags = null, $fields = null, $with = null, $exclude = null);

    /**
     * Проверяет, пустая ли модель
     * @return bool
     */
    abstract public function isEmpty(): bool;

    /**
     * Устанавливает ключи отношений
     * @return void
     */
    abstract public function setRelationKeys(): void;

    /**
     * Удаляет модель
     * @param bool $withRelations
     * @return mixed
     */
    abstract public function delete($withRelations = false);
}
