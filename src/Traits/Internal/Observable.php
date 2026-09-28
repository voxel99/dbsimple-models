<?php

namespace Jam\Models\Traits\Internal;

/**
 * Observable trait
 *
 * Simple usage:
 * <code>
 * class Model {
 *      use Observable;
 *    public function __construct() {
 *            $this->on('insert', function($row){
 *                $row['created_at'] = date('Y-m-d H:i:s');
 *                return $row;
 *                });
 *    }
 *
 *    public function insert($data) {
 *        $data = $this->fire('insert', $data);
 *    }
 * }
 * </code>
 */
trait Observable
{
    private array $thisTraitEvents = [];

    public function on(string $event, callable $callback): void
    {
        $this->thisTraitEvents[$event][] = $callback;
    }

    /**
     * Вызывает обработчики события по цепочке. Последний аргумент — «данные»:
     * каждый обработчик получает все аргументы и возвращает новые данные для следующего.
     *
     * @return mixed Данные после всех обработчиков
     */
    public function fire(string $event, mixed ...$args): mixed
    {
        $last = count($args) - 1;
        foreach ($this->thisTraitEvents[$event] ?? [] as $callable) {
            $args[$last] = $callable(...$args);
        }
        return $args[$last] ?? [];
    }
}
