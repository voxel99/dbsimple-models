<?php

namespace Jam\Models\Traits\Crud;

use Exception;
use Jam\Models\Model;
use Jam\Models\ModelList;
use Jam\Models\ModelException;
use Jam\Models\OnRetrieved;
use Jam\Models\Relation;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Utils\Code;
use Jam\Models\Utils\Strings;
use Jam\Models\StringableInterface;

trait Withable
{
    /** Скобки with-выражения: (поля), [условие], ^исключения^ */
    private const WITH_OPENING_BRACES = ["(", "[", "^"];
    private const WITH_CLOSING_BRACES = [")", "]", "^"];

    /**
     * @param array<string, mixed> $withData
     * @return array{
     *     flags: array<int|string, mixed>,
     *     fields: array<int, string>,
     *     exclude: array<int, string>,
     *     required: bool,
     *     ret_empty: bool,
     *     where: array<int, mixed>,
     *     with: array<string, mixed>
     * }
     */
    private function normalizeWithData(array $withData = []): array
    {
        return [
            'flags' => is_array($withData['flags'] ?? null) ? $withData['flags'] : [],
            'fields' => array_values($withData['fields'] ?? []),
            'exclude' => array_values($withData['exclude'] ?? []),
            'required' => (bool)($withData['required'] ?? false),
            'ret_empty' => (bool)($withData['ret_empty'] ?? false),
            'where' => array_values($withData['where'] ?? []),
            'with' => is_array($withData['with'] ?? null) ? $withData['with'] : [],
        ];
    }

    /**
     * Массив связей
     * @var Relation[]
     */
    protected $with = [];

    public function bootWithable()
    {
        $this->on(Model::EVENT_RETRIEVED, function ($rows) {
            $dispatcher = new OnRetrieved($this, $rows);
            return $dispatcher->dispatch();
        });
    }

    /**
     * Убрать связи из with (без аргументов — все)
     * @param string|array<int, string> $relations
     * @return $this
     */
    public function without($relations = '')
    {
        if (!$relations) {
            $this->with = [];
            return $this;
        }
        if (!is_array($relations)) {
            $relations = ArrayHelper::stringCommasToArray($relations);
        }
        foreach ($relations as $alias) {
            unset($this->with[$alias]);
        }
        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     * @throws Exception
     */
    private function parseStringWithExpression(string $classesExpression): array
    {
        $parsed = [];

        // Запятые внутри (полей), [условий] и ^исключений^ не разделяют отношения
        foreach (Strings::reSplitOutBraces($classesExpression, ",", self::WITH_OPENING_BRACES, self::WITH_CLOSING_BRACES) as $classStr) {
            $this->appendStringWithRelation($parsed, $classStr, $classesExpression);
        }

        return $parsed;
    }

    /**
     * @param array<string, array<string, mixed>> $parsed
     * @throws Exception
     */
    private function appendStringWithRelation(array &$parsed, string $classStr, string $classesExpression): void
    {
        $with = &$parsed;
        // Точка внутри [условия] (например, rating > 1.5) не является разделителем вложенности
        $parts = explode("\0", Strings::replaceOutBraces($classStr, ".", "\0", self::WITH_OPENING_BRACES, self::WITH_CLOSING_BRACES));

        foreach ($parts as $indexDeep => $partsStr) {
            if (empty($partsStr)) {
                $debugPos = 0;
                for ($i = 0; $i < $indexDeep; $i++) {
                    $debugPos += mb_strlen($parts[$i]);
                }
                throw new ModelException(sprintf(
                    "Empty relation (pos: %d) in expression: %s",
                    $debugPos,
                    $classesExpression
                ));
            }

            [$alias, $fields, $where, $exclude, $required, $retEmpty]
                = $this->parseRelationAlias($partsStr);
            $flags = $this->parseFlags($partsStr);

            $withData = $this->normalizeWithData([
                "flags" => $flags,
                "fields" => $fields,
                "exclude" => $exclude,
                "required" => $required,
                "ret_empty" => $retEmpty,
                "where" => $where ? [$where] : [],
                "with" => [],
            ]);

            $with[$alias] = $this->mergeWithNode($with[$alias] ?? [], $withData);
            $with = &$with[$alias]["with"];
        }
    }

    /**
     * @param array<string, mixed> $existingData
     * @param array<string, mixed> $withData
     * @return array<string, mixed>
     */
    private function mergeWithNode(array $existingData, array $withData): array
    {
        $merged = $this->normalizeWithData(
            !empty($existingData)
                ? ArrayHelper::mergeDeep($existingData, $withData)
                : $withData
        );

        // Флаги — ассоциативный массив «буква => значение», уникальность обеспечена ключами.
        // array_unique здесь нельзя: true == 'id' при нестрогом сравнении, и :p:o(id) терял флаг o.
        $merged["fields"] = array_values(array_unique($merged["fields"]));
        $merged["exclude"] = array_values(array_unique($merged["exclude"]));
        $merged["where"] = array_values(array_unique($merged["where"]));

        return $merged;
    }

    /**
     * @param array<int|string, mixed> $classesExpression
     * @return array<string, array<string, mixed>>
     */
    private function parseArrayWithExpression(array $classesExpression): array
    {
        $parsed = [];

        foreach ($classesExpression as $k => $v) {
            if ((is_numeric($k) && !is_string($v)) || (!is_numeric($k) && !is_array($v))) {
                throw new ModelException(sprintf(
                    "Can not parse 'with' expression in key %s: %s",
                    $k,
                    json_encode($classesExpression)
                ));
            }

            if (is_numeric($k)) {
                $parsed[$v] = $this->normalizeWithData();
                continue;
            }

            $parsed[$k] = $this->normalizeWithData($v);
        }

        return $parsed;
    }

    /**
     * @param array<string, array<string, mixed>> $parsed
     */
    private function applyParsedWith(array $parsed): void
    {
        $relationsAll = $this->getAllRelations();

        foreach ($parsed as $alias => $withData) {
            if ($this->applyParsedWithToRelation($relationsAll, $alias, $withData)) {
                continue;
            }

            $explicit = $this->getExplicit();
            if (!empty($explicit) && in_array($alias, $explicit)) {
                $this->with[$alias] = self::EXPLICIT;
                continue;
            }

            if ($withData['required']) {
                throw new ModelException(sprintf(
                    "Алиас %s не найден в отношениях модели %s",
                    $alias,
                    get_class($this)
                ));
            }
        }
    }

    /**
     * @param array<string, Relation> $relationsAll
     * @param array<string, mixed> $withData
     */
    private function applyParsedWithToRelation(array $relationsAll, string $alias, array $withData): bool
    {
        /** @var Relation $R */
        foreach ($relationsAll as $R) {
            if (($alias !== $R->alias()) && ($alias !== $R->getClass())) {
                continue;
            }

            if (!empty($withData["with"])) {
                $R->setWithArray($withData["with"]);
            }
            if (!empty($withData["fields"])) {
                $R->setFields($withData["fields"]);
            }
            if (!empty($withData["exclude"])) {
                $R->setExclude($withData["exclude"]);
            }
            if (!empty($withData["where"])) {
                $R->setWheres($withData["where"]);
            }
            if (!empty($withData["flags"])) {
                $R->setFlags($withData["flags"]);
            }
            if (!empty($withData["required"])) {
                $R->setRequired(true);
            }
            if (!empty($withData["ret_empty"])) {
                $R->setReturnEmpty(true);
            }

            $this->with[$R->alias()] = $R;
            return true;
        }

        return false;
    }

    /**
     * Получение связанных данных модели
     *
     * @param string|array $classesExpression Список связанных моделей
     * Может задаваться строкой, где модели разделены запятыми
     * <code>$model->with('tags,galleries')</code>
     * Может задаваться массивом строк:
     * <code>$model->with(['tags','galleries'])</code>
     * @return $this
     * @throws Exception
     */
    public function with($classesExpression)
    {
        $parsed = is_array($classesExpression)
            ? $this->parseArrayWithExpression($classesExpression)
            : $this->parseStringWithExpression($classesExpression);

        $this->applyParsedWith($parsed);
        return $this;
    }

    public function getWithArray($convertToArray = false)
    {
        $ret = $this->with;
        if ($convertToArray) {
            foreach ($ret as $alias => $R) {
                if ($R !== self::EXPLICIT) {
                    $ret[$alias] = $R->toArray();
                }
            }
        }
        return $ret;
    }

    public function mergeWithArray(array $with)
    {
        $this->with = array_merge($this->with, $with);
    }
}
