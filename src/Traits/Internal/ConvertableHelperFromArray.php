<?php

namespace Jam\Models\Traits\Internal;

use Jam\Models\Cast\CastClosure;
use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\ModelList;
use Jam\Models\Relation;
use Jam\Models\StringableInterface;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Utils\Code;

class ConvertableHelperFromArray
{
    /** @var Model */
    protected $model;

    /**
     * Конвертирование с подавлением исключения ModelException
     * @var bool
     */
    protected bool $silence;

    public function __construct(Model &$model, bool $silence)
    {
        $this->model = $model;
        $this->silence = $silence;
    }

    private function setKVPropNode(&$prop, $k, $node, $makeArray)
    {
        if ($makeArray) {
            $prop[$k] = $node;
        } else {
            if ($this->silence) {
                try {
                    $prop->$k = $node;
                } catch (ModelException $e) {
                    $this->processSilentException($e);
                }
            } else {
                $prop->$k = $node;
            }
        }
    }

    private function convertProp(&$prop, $data, $makeArray)
    {
        $cast = [];
        $isModel = $prop instanceof Model;
        if ($isModel) {
            $cast = $prop->getCast();
        }

        $modelObject = $this->model;
        // ee(get_class($this->model), $data);
        // Перебираем весь массив инициализации для свойства prop (которое в корне является $this модели)
        foreach ($data as $k => $v) {
            // Ключ связи в данных означает, что связь загружена — даже если она пуста (null или [])
            $isRelation = $isModel && $prop->isRelationProp($k);
            if ($isRelation) {
                $prop->markRelationLoaded($k);
                // Пустая связь в свойство не попадает: $model->rel остаётся null, чтобы
                // empty()/isset()/if ($model->rel) работали как раньше; факт загрузки — в isRelationLoaded()
                if ($v === []) {
                    continue;
                }
            }
            // Игнорируем null-значения
            if (is_null($v) || (empty($v) && is_scalar($v) && !empty($cast[$k]->is_null))) {
                continue;
            }

            // Данные строки могут быть развёрнуты в модель
            if (is_string($v) && $modelObject instanceof StringableInterface && $modelObject::hasString($k, $v)) {
                $v = $modelObject::fromString($k, $v);
            }
            // Быстрый путь: скаляр в поле модели (не отношение) записывается как есть — ровно то,
            // что вернул бы makeNode(getPropClassData()), без промежуточного объекта и вызовов.
            // Пользовательский каст (closure) идёт общим путём: там создаётся его экземпляр.
            if (
                $isModel && is_scalar($v)
                && (!isset($cast[$k]) || ($cast[$k]->type ?? null) !== CastClosure::TYPE_CLOSURE)
                && !$isRelation
            ) {
                $this->setKVPropNode($prop, $k, $v, $makeArray);
                continue;
            }
            $convertableData = $this->getPropClassData($prop, $k, $v, $cast);
            $node = $this->makeNode($convertableData);
            $this->setKVPropNode($prop, $k, $node, $makeArray);
        }
    }

    public function convert(array $data)
    {
        $this->convertProp($this->model, $data, false);
    }

    private function processSilentException(ModelException $e)
    {
        error_log('Silent convert error: ' . $e->getMessage());
    }

    private function getPropClassData($prop, $k, $v, array $cast): ConvertableHelperFromArrayData
    {
        $convertableHelperData = new ConvertableHelperFromArrayData($v);
        $isModelList = $prop instanceof ModelList;

        // Если свойство, которое мы инициализируем - это модель
        // и ключ с данными является связью Relation модели
        if ($prop instanceof Model) {
            // Проинициализируем $className данными связи
            if ($prop->isRelationProp($k)) {
                /** @var Relation $R */
                $R = $prop->getRelationByAlias($k);
                if ($R->isTypeMany()) {
                    $convertableHelperData->className = ModelList::class;
                    // Пустые данные дают пустой ModelList: связь загружена, но записей нет
                    $convertableHelperData->classArgs = [$R->getClass(), $v, $this->silence];
                } else {
                    $convertableHelperData->className = $R->getClass();
                    $convertableHelperData->classArgs = [$v, $this->silence];
                }
            } elseif (isset($cast[$k])) {
                $closureClass = CastClosure::sharedForField($prop::class, $k, $cast[$k]);
                $convertableHelperData->castToArray = is_array($v) && $closureClass && $closureClass->isCastToArray();
            }
        } elseif ($isModelList) {
            // Проинициализируем $className данными связанного класса
            $convertableHelperData->className = $prop->getClass();
        }
        if (!$convertableHelperData->castToArray) {
            $convertableHelperData->castToArray = (is_array($v) && ArrayHelper::isArrayOfArrays($v)) || $isModelList;
        }
        return $convertableHelperData;
    }

    private function makeNode(ConvertableHelperFromArrayData $convertableHelperData)
    {
        $v = $convertableHelperData->v;
        // Уже готовая модель/список (например, new Post(['author' => $user])) — используем как есть
        if ($v instanceof \Jam\Models\ModelAbstract) {
            return $v;
        }
        if ($convertableHelperData->className) {
            /*
                Добавил проверку is_scalar, т.к. при передаче 'source' => 'www.link.data', вызывался
                конструктор new Source(['www.link.data']) и сваливался по ошибке '0 is a not correct field of Source'.
                Передача такого параметра некорректна и более корректно оставить его в том же виде, т.е. 'source' => 'www.link.data'
            */
            if (is_scalar($v) && Code::isImplements($convertableHelperData->className, \Jam\Models\ScalarableInterface::class)) {
                $className = $convertableHelperData->className;
                $node = $className::fromScalar($v);
                if (!$node || !($node instanceof Model)) {
                    throw new ModelException(sprintf("%s::fromScalar must return Model instance", $className));
                }
            } elseif (class_exists($convertableHelperData->className)) {
                // То же, что ReflectionClass::newInstanceArgs(), без создания объекта Reflection
                $className = $convertableHelperData->className;
                $node = new $className(...$convertableHelperData->classArgs);
            } else {
                // Несуществующий класс: прежнее исключение (ReflectionException)
                $Reflection = new \ReflectionClass($convertableHelperData->className);
                $node = $Reflection->newInstanceArgs($convertableHelperData->classArgs);
            }
        } else {
            if ($convertableHelperData->castToArray) {
                $node = (array) $v;
            } elseif (is_scalar($v)) {
                $node = $v;
            } else {
                $node = (object) $v;
            }
        }
        return $node;
    }
}
