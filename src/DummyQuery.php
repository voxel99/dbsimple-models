<?php

namespace Jam\Models;

use Jam\Models\Utils\ArrayHelper;

/**
 * Выполнение where/orderBy/limit/offset над строками DummyModel::dummyRows() в памяти.
 *
 * Поддерживаются условия вида `field = value`, `field IN (a, b)` и их комбинации
 * через AND или OR (но не одновременно).
 */
final class DummyQuery
{
    /**
     * @param class-string $modelClass Для сообщений об ошибках
     * @param array<int, string> $allFields Поля модели
     */
    public function __construct(
        private string $modelClass,
        private array $allFields
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $whereConditions Условия с уже подставленными плейсхолдерами
     * @param array<int, string>|null $fields
     * @return array<int, array<string, mixed>>
     */
    public function run(array $rows, array $whereConditions, ?array $fields, $limit, $offset, $orderby): array
    {
        foreach ($whereConditions as $where) {
            $rows = $this->filterRows($rows, $where);
        }

        $rows = $this->orderRows($rows, $orderby);
        $rows = $this->sliceRows($rows, $limit, $offset);
        return $fields ? ArrayHelper::cutFields($rows, $fields) : $rows;
    }

    /**
     * @return array{0: array<int, string>, 1: bool, 2: bool}
     */
    private function resolveConditions(string $where): array
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
    private function parseCondition(string $condition, string $where): array
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

        if (!in_array($key, $this->allFields)) {
            throw new ModelException(sprintf(
                'DummyModel %s havn`t field %s in expression %s',
                $this->modelClass,
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
    private function findMatchedIndexes(array $rows, string $key, string|array $value, bool $isInCondition): array
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
    private function mergeConditionIndexes(array $resultIndexes, bool $isAndCondition): array
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
    private function filterRows(array $rows, string $where): array
    {
        [$conditions, , $isAndCondition] = $this->resolveConditions($where);
        $resultIndexes = [];

        foreach ($conditions as $conditionIndex => $condition) {
            [$key, $value, $isInCondition] = $this->parseCondition($condition, $where);
            $resultIndexes[$conditionIndex] = $this->findMatchedIndexes($rows, $key, $value, $isInCondition);
        }

        if (empty($resultIndexes)) {
            return [];
        }

        $filteredRows = [];
        foreach ($this->mergeConditionIndexes($resultIndexes, $isAndCondition) as $index) {
            $filteredRows[] = $rows[$index];
        }

        return $filteredRows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function orderRows(array $rows, $orderby): array
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
    private function sliceRows(array $rows, $limit, $offset): array
    {
        if (!$offset && !$limit) {
            return $rows;
        }

        return array_slice($rows, $offset ?? 0, $limit);
    }
}
