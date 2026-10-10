<?php

/**
 * Замер присваивания свойств модели (__set) без методов change*.
 * Модель с ~250 методами, 100k присваиваний.
 *
 *   php benchmarks/assign-props.php [iterations]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Jam\DbSimple\Adapter\Sqlite;
use Jam\Models\Model;

Model::initDbSimple([Model::DB_MASTER => new Sqlite(['path' => ':memory:'])]);

$ownMethods = 250 - count(get_class_methods(Model::class));
$methods = '';
for ($i = 0; $i < max(0, $ownMethods); $i++) {
    $methods .= "public function method{$i}() { return {$i}; }\n";
}
eval("class BenchAssignModel extends \\Jam\\Models\\Model {
    protected string \$fields = 'id, title, body, user_id, created_at';
    {$methods}
}");

$iterations = (int) ($argv[1] ?? 100000);
$model = new BenchAssignModel();
$props = ['title', 'body', 'user_id', 'created_at'];

// прогрев
for ($i = 0; $i < 1000; $i++) {
    $model->{$props[$i & 3]} = 'v' . $i;
}

$best = INF;
for ($run = 0; $run < 5; $run++) {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $model->{$props[$i & 3]} = 'v';
    }
    $best = min($best, (hrtime(true) - $start) / 1e6);
}

printf(
    "methods: %d, assignments: %d, best of 5: %.1f ms (%.2f us/assign)\n",
    count(get_class_methods($model)),
    $iterations,
    $best,
    $best * 1000 / $iterations
);

// Только поиск change* (applyDynamicChangeMethod), без остальной логики __set
$apply = Closure::bind(
    fn (string $prop, $value) => $this->applyDynamicChangeMethod($prop, $value, 'db'),
    $model,
    Model::class
);
$best = INF;
for ($run = 0; $run < 5; $run++) {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $apply($props[$i & 3], 'v');
    }
    $best = min($best, (hrtime(true) - $start) / 1e6);
}
printf("applyDynamicChangeMethod only: best of 5: %.1f ms (%.2f us/call)\n", $best, $best * 1000 / $iterations);
