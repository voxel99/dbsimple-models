<?php

/**
 * Бенчмарк гидрации и сериализации моделей (не входит в основной сьют).
 *
 *   php bench/hydrate.php [--runs=5] [--posts=85] [--only=a,c] [--json]
 *
 * Фикстуры: tests/Fixtures/Perf (Post ~60 полей / ~25 отношений, вложенные User → avatar → thumbs,
 * теги через pivot, подборки, полиморфные вложения с крупным upload, превью, комментарии).
 * Для каждого сценария печатается медиана и минимум wall-времени из N прогонов, медиана
 * CPU-времени процесса (getrusage, устойчивее к шуму соседних процессов) и пиковая память.
 */

declare(strict_types=1);

use Jam\Models\ModelList;
use Jam\Models\Tests\Fixtures\Perf\PerfData;
use Jam\Models\Tests\Fixtures\Perf\Post;

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['runs::', 'posts::', 'only::', 'json']);
$runs = (int) ($opts['runs'] ?? 5);
$postsCount = (int) ($opts['posts'] ?? 85);
$only = isset($opts['only']) ? explode(',', (string) $opts['only']) : null;

$with = (new Post())->with(PerfData::WITH)->getWithArray(true);

/**
 * Каждый сценарий: setup() готовит вход вне замера, run($input) — измеряемая часть.
 * @var array<string, array{title: string, setup: Closure, run: Closure}> $scenarios
 */
$scenarios = [
    'a' => [
        'title' => "new Post(\$row) ×$postsCount, полная вложенность",
        'setup' => fn() => PerfData::posts($postsCount),
        'run' => function (array $rows) {
            $ret = [];
            foreach ($rows as $row) {
                $ret[] = new Post($row);
            }
            return $ret;
        },
    ],
    'b' => [
        'title' => "ModelList::fromArray ×$postsCount",
        'setup' => fn() => PerfData::posts($postsCount),
        'run' => fn(array $rows) => new ModelList(Post::class, $rows),
    ],
    'c' => [
        'title' => "ModelList::toArray(':dc', with: поля/exclude/pivot)",
        'setup' => fn() => new ModelList(Post::class, PerfData::posts($postsCount)),
        'run' => fn(ModelList $list) => $list->toArray(':dc', null, $with),
    ],
    'c2' => [
        'title' => 'ModelList::toArray() по умолчанию',
        'setup' => fn() => new ModelList(Post::class, PerfData::posts($postsCount)),
        'run' => fn(ModelList $list) => $list->toArray(),
    ],
    'd' => [
        'title' => '100k __get/__set/__isset на одной модели',
        'setup' => fn() => new Post(PerfData::posts(1)[0]),
        'run' => function (Post $post) {
            $sum = 0;
            for ($i = 0; $i < 20000; $i++) {
                $post->likes = $i;              // __set: int-каст
                $sum += $post->likes;           // __get
                $sum += strlen($post->title);   // __get
                $sum += strlen($post->url);     // __get: computed
                $sum += isset($post->lead) ? 1 : 0; // __isset
            }
            return $sum;
        },
    ],
    'e' => [
        'title' => "Model::map(\$rows) ×$postsCount (путь collection() без SQL)",
        'setup' => fn() => PerfData::posts($postsCount),
        'run' => fn(array $rows) => (new Post())->map($rows),
    ],
];

$results = [];
foreach ($scenarios as $key => $scenario) {
    if ($only && !in_array($key, $only, true)) {
        continue;
    }
    // Прогрев: автозагрузка классов и статические кеши заполняются до замера
    $scenario['run']($scenario['setup']());
    $times = [];
    $cpus = [];
    $mems = [];
    for ($r = 0; $r < $runs; $r++) {
        $input = $scenario['setup']();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memBefore = memory_get_usage();
        $ru = getrusage();
        $t = hrtime(true);
        $out = $scenario['run']($input);
        $times[] = (hrtime(true) - $t) / 1e6;
        $ru2 = getrusage();
        $cpus[] = ($ru2['ru_utime.tv_sec'] - $ru['ru_utime.tv_sec'] + $ru2['ru_stime.tv_sec'] - $ru['ru_stime.tv_sec']) * 1e3
            + ($ru2['ru_utime.tv_usec'] - $ru['ru_utime.tv_usec'] + $ru2['ru_stime.tv_usec'] - $ru['ru_stime.tv_usec']) / 1e3;
        $mems[] = memory_get_peak_usage() - $memBefore;
        unset($out, $input);
    }
    sort($times);
    sort($cpus);
    sort($mems);
    $results[$key] = [
        'title' => $scenario['title'],
        'ms' => round($times[intdiv(count($times), 2)], 2),
        'min_ms' => round($times[0], 2),
        'cpu_ms' => round($cpus[intdiv(count($cpus), 2)], 2),
        'mem_kb' => (int) round($mems[intdiv(count($mems), 2)] / 1024),
    ];
}

if (isset($opts['json'])) {
    echo json_encode(['php' => PHP_VERSION, 'runs' => $runs, 'posts' => $postsCount, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

printf("PHP %s, opcache %s, runs=%d, posts=%d\n\n", PHP_VERSION, ini_get('opcache.enable_cli') ? 'on' : 'off', $runs, $postsCount);
printf("%-4s %-62s %10s %10s %10s %10s\n", '', 'scenario', 'median ms', 'min ms', 'cpu ms', 'peak KB');
foreach ($results as $key => $r) {
    printf("%-4s %s %10.2f %10.2f %10.2f %10d\n", $key, mb_str_pad(mb_strimwidth($r['title'], 0, 62), 62), $r['ms'], $r['min_ms'], $r['cpu_ms'], $r['mem_kb']);
}
