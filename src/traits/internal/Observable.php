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

    public function fire($event, /*,...,*/ $data)
    {
        $args = func_get_args();
        $event = array_shift($args);
        $data = $args ? $args[count($args) - 1] : [];
        if (isset($this->thisTraitEvents[$event])) {
            foreach ($this->thisTraitEvents[$event] as $callable) {
                $data = call_user_func_array($callable, $args);
                $args[count($args) - 1] = $data;
            }
        }
        return $data;
    }
}
