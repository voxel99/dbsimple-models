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
        if ($prop instanceof Model) {
            $cast = $prop->getCast();
        }

        $modelObject = $this->model;
        // ee(get_class($this->model), $data);
        // Перебираем весь массив инициализации для свойства prop (которое в корне является $this модели)
        foreach ($data as $k => $v) {
            // Игнорируем null-значения
            if (is_null($v) || (empty($v) && is_scalar($v) && !empty($cast[$k]->is_null))) {
                continue;
            }

            // Данные строки могут быть развёрнуты в модель
            if (is_string($v) && $modelObject instanceof StringableInterface && $modelObject::hasString($k, $v)) {
                $v = $modelObject::fromString($k, $v);
            }
            $convertableData = $this->getPropClassData($prop, $k, $v, $cast);
            if ($convertableData->skipNode) {
                continue;
            }
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
                    $convertableHelperData->classArgs = [$R->getClass(), $v, $this->silence];
                    if (empty((array)$v)) {
                        $convertableHelperData->skipNode = true;
                    }
                } else {
                    $convertableHelperData->className = $R->getClass();
                    $convertableHelperData->classArgs = [$v, $this->silence];
                }
            } elseif (isset($cast[$k])) {
                $closureClass = CastClosure::fromCast($cast[$k]);
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
            } else {
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
