<?php

/**
 * Сэмплирующий профиль сценариев бенчмарка через Excimer (pecl excimer; только для разработки).
 *
 *   php bench/profile.php [--only=a,c] [--period=0.0005] [--top=40] [--repeat=5]
 */

declare(strict_types=1);

use Jam\Models\ModelList;
use Jam\Models\Tests\Fixtures\Perf\PerfData;
use Jam\Models\Tests\Fixtures\Perf\Post;

require __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('excimer')) {
    fwrite(STDERR, "ext-excimer is required: pecl install excimer\n");
    exit(1);
}

$opts = getopt('', ['only::', 'period::', 'top::', 'repeat::']);
$only = explode(',', (string) ($opts['only'] ?? 'a,c'));
$period = (float) ($opts['period'] ?? 0.0005);
$top = (int) ($opts['top'] ?? 40);
$repeat = (int) ($opts['repeat'] ?? 5);

$with = (new Post())->with(PerfData::WITH)->getWithArray(true);
$rows = PerfData::posts();
$list = new ModelList(Post::class, PerfData::posts());

$profiler = new ExcimerProfiler();
$profiler->setPeriod($period);
$profiler->setEventType(EXCIMER_CPU);
$profiler->start();
for ($i = 0; $i < $repeat; $i++) {
    if (in_array('a', $only, true)) {
        foreach ($rows as $row) {
            new Post($row);
        }
    }
    if (in_array('c', $only, true)) {
        $list->toArray(':dc', null, $with);
    }
    if (in_array('d', $only, true)) {
        $post = $list[0];
        for ($j = 0; $j < 20000; $j++) {
            $post->likes = $j;
            $post->likes;
            $post->title;
            $post->url;
            isset($post->lead);
        }
    }
}
$profiler->stop();

$log = $profiler->getLog();
$total = count($log);
// aggregateByFunction() считает inclusive по каждому кадру, и при рекурсии (вложенные модели)
// одна функция попадает в сэмпл много раз. Считаем inclusive сами: не больше 1 на сэмпл.
$inclusive = [];
foreach ($log->aggregateByFunction() as $fn => $info) {
    $self[$fn] = $info['self'];
}
foreach ($log as $entry) {
    $seen = [];
    foreach ($entry->getTrace() as $frame) {
        $fn = isset($frame['class']) ? $frame['class'] . '::' . ($frame['function'] ?? '') : ($frame['closure_function'] ?? $frame['function'] ?? $frame['file'] ?? '?');
        $seen[$fn] = true;
    }
    foreach ($seen as $fn => $_) {
        $inclusive[$fn] = ($inclusive[$fn] ?? 0) + 1;
    }
}
$print = function (string $title, array $counts) use ($total, $top) {
    arsort($counts);
    printf("\n== %s (samples: %d)\n", $title, $total);
    foreach (array_slice($counts, 0, $top, true) as $fn => $n) {
        printf("%6d %5.1f%%  %s\n", $n, 100 * $n / max(1, $total), str_replace('Jam\\Models\\', '', (string) $fn));
    }
};
$print('inclusive', $inclusive);
$print('self', $self);
