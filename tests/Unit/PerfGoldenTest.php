<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\Model;
use Jam\Models\ModelList;
use Jam\Models\Tests\Fixtures\Perf\PerfData;
use Jam\Models\Tests\Fixtures\Perf\Post;
use Jam\Models\Tests\Support\ModelTestCase;

/**
 * Golden-тест: результат гидрации и сериализации на фикстурах бенчмарка (tests/Fixtures/Perf)
 * должен совпадать байт в байт со снимком, снятым до оптимизаций.
 *
 * Снимок хранится в gzip (JSON ~2 МБ). При расхождении фактический JSON пишется во временный
 * каталог — его удобно сравнить с `zcat tests/Fixtures/Perf/golden.json.gz`.
 *
 * Перегенерировать снимок (только если изменение формата осознанное):
 *   UPDATE_GOLDEN=1 vendor/bin/phpunit --filter PerfGoldenTest
 */
final class PerfGoldenTest extends ModelTestCase
{
    private const string GOLDEN = __DIR__ . '/../Fixtures/Perf/golden.json.gz';

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = PerfData::posts(4);
        $with = (new Post())->with(PerfData::WITH)->getWithArray(true);
        $list = new ModelList(Post::class, $rows);
        $posts = [];
        foreach ($rows as $row) {
            $posts[] = new Post($row);
        }

        $snap = [
            'with' => $with,
            'list_default' => $list->toArray(),
            'list_dc_with' => $list->toArray(':dc', null, $with),
            'list_all_flags' => $list->toArray(':dchxue'),
            'list_db_layer' => $list->toArray(':_dhx'),
            'list_fields' => $list->toArray(':c', 'id,title,url,author,tags', null, ['body', 'lead'], true),
            'list_pivot_fields' => $list->toArray(':dp(position|is_main)'),
            'map' => (new Post())->map($rows)->toArray(':dc'),
            'to_object' => $posts[0]->toObject(':dc'),
            'clear_excess' => Post::clearExcessFields($rows[1] + ['unknown' => 1]),
            'unserialized' => unserialize(serialize($posts[2]))->toArray(':dhx'),
            'debug_info' => $posts[3]->__debugInfo(),
        ];

        // Чтение/запись свойств: касты, computed, change*-хуки, json top-level ключи
        $p = $posts[3];
        $p->title = '  New title  ';
        $p->likes = '42';
        $p->seo_title = 'Changed SEO';
        $p->flags = (object) ['nsfw' => 1, 'featured' => 'yes'];
        $p->keywords = ['a', 'b'];
        $p->highlight = 'hl';
        $p->author->login = '  MixedCase  ';
        $snap['props'] = [
            'title' => $p->title,
            'likes' => $p->likes,
            'likes_db' => $p->{'likes:db'},
            'flags_db' => $p->{'flags:db'},
            'keywords_db' => $p->{'keywords:db'},
            'meta' => $p->meta,
            'url' => $p->url,
            'excerpt' => $p->excerpt,
            'highlight' => $p->highlight,
            'isset_lead' => isset($p->lead),
            'isset_none' => isset($p->deleted_at),
            'login' => $p->author->login,
            'thumbs' => count($p->author->avatar->thumbs),
            'attach_classes' => array_map(fn($a) => $a->upload ? get_class($a->upload) : null, iterator_to_array($p->attaches)),
            'pivot_class' => get_class($p->tags[0]->{Model::PIVOT_FIELD}),
            'after' => $p->toArray(':dchxue', null, $with),
        ];
        return $snap;
    }

    public function testSnapshotMatchesGolden(): void
    {
        $json = json_encode($this->snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
        if (getenv('UPDATE_GOLDEN')) {
            file_put_contents(self::GOLDEN, gzencode($json, 9));
        }
        $this->assertFileExists(self::GOLDEN);
        $expected = gzdecode((string) file_get_contents(self::GOLDEN));
        if ($expected !== $json) {
            $actualFile = sys_get_temp_dir() . '/perf-golden.actual.json';
            file_put_contents($actualFile, $json);
            $this->fail('Snapshot differs from golden, actual result: ' . $actualFile);
        }
        $this->addToAssertionCount(1);
    }
}
