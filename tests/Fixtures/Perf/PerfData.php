<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

/**
 * Детерминированные данные, похожие на ответ прод-сервиса: N постов со вложенными
 * отношениями в виде, в каком их отдаёт OnRetrieved (строки из БД — значения строками).
 */
final class PerfData
{
    /**
     * with-выражение реальных вызовов: поля, исключения, pivot, флаги по отношениям
     */
    public const string WITH = 'author(id,login,name,surname,avatar_id,status,flags,settings,rating):c.avatar.thumbs,'
        . 'editor:c,tags:p(position|is_main):c,selections.cover,attaches^meta^.upload:c,preview.image.thumbs,'
        . 'comments:c.author,cover.thumbs,category';

    /** @return array<int, array<string, mixed>> */
    public static function posts(int $count = 85): array
    {
        mt_srand(20261010);
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = self::post($i);
        }
        return $rows;
    }

    private static function n(int $min, int $max): string
    {
        return (string) mt_rand($min, $max);
    }

    private static function text(int $words): string
    {
        static $dict = ['lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do',
            'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore', 'magna', 'aliqua', 'пост', 'новость'];
        $ret = [];
        for ($i = 0; $i < $words; $i++) {
            $ret[] = $dict[mt_rand(0, count($dict) - 1)];
        }
        return implode(' ', $ret);
    }

    private static function date(): string
    {
        return sprintf('2026-%02d-%02d %02d:%02d:%02d', mt_rand(1, 9), mt_rand(1, 28), mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59));
    }

    /** @return array<string, mixed> */
    private static function thumb(int $imageId, int $k): array
    {
        $w = [64, 96, 128, 160, 240, 320, 480, 640, 800, 960, 1024, 1280, 1600, 1920, 2560][$k % 15];
        return [
            'id' => (string) ($imageId * 100 + $k),
            'image_id' => (string) $imageId,
            'kind' => 'w' . $w,
            'width' => (string) $w,
            'height' => (string) intdiv($w * 3, 4),
            'url' => "https://cdn.example.com/i/$imageId/w$w.webp",
            'format' => $k % 2 ? 'webp' : 'jpg',
            'size' => self::n(2000, 400000),
            'retina' => (string) ($k % 2),
        ];
    }

    /** @return array<string, mixed> */
    private static function image(int $id, int $userId, int $thumbs): array
    {
        $row = [
            'id' => (string) $id,
            'user_id' => (string) $userId,
            'path' => "i/$id/orig.jpg",
            'width' => self::n(800, 4000),
            'height' => self::n(600, 3000),
            'mime' => 'image/jpeg',
            'size' => self::n(100000, 9000000),
            'meta' => json_encode(['camera' => 'X100', 'iso' => mt_rand(100, 3200), 'exif' => ['f' => 2.8, 'exp' => '1/250']]),
            'focus' => '0.5,0.5',
            'created_at' => self::date(),
        ];
        for ($k = 0; $k < $thumbs; $k++) {
            $row['thumbs'][] = self::thumb($id, $k);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private static function user(int $id): array
    {
        return [
            'id' => (string) $id,
            'login' => 'User' . $id,
            'email' => "user$id@example.com",
            'name' => 'Name' . $id,
            'surname' => 'Surname' . $id,
            'password_hash' => '$2y$04$' . md5((string) $id),
            'avatar_id' => (string) (1000 + $id),
            'cover_id' => '0',
            'status' => '1',
            'role' => 'author',
            'karma' => self::n(-10, 900),
            'rating' => self::n(0, 500) . '.25',
            'followers' => self::n(0, 9000),
            'following' => self::n(0, 300),
            'posts_count' => self::n(1, 400),
            'flags' => self::n(0, 15),
            'settings' => json_encode(['theme' => 'dark', 'lang' => 'ru', 'notify' => ['mail' => 1, 'push' => 0], 'x_beta' => 1]),
            'city' => 'Moscow',
            'country' => 'RU',
            'about' => self::text(20),
            'site' => 'https://example.com/' . $id,
            'birthday' => '1990-01-' . sprintf('%02d', $id % 28 + 1),
            'gender' => 'm',
            'locale' => 'ru_RU',
            'last_seen_at' => self::date(),
            'created_at' => self::date(),
            'updated_at' => self::date(),
            'avatar' => self::image(1000 + $id, $id, 15),
        ];
    }

    /** @return array<string, mixed> */
    private static function upload(int $id, string $type): array
    {
        $variants = [];
        for ($k = 0; $k < 12; $k++) {
            $variants[] = ['w' => 100 * ($k + 1), 'h' => 75 * ($k + 1), 'url' => "https://cdn.example.com/u/$id/$k.webp", 'size' => mt_rand(1000, 90000)];
        }
        $row = [
            'id' => (string) $id,
            'user_id' => self::n(1, 40),
            'type' => $type,
            'path' => "u/$id/file",
            'name' => "file$id",
            'mime' => $type === 'video' ? 'video/mp4' : 'image/jpeg',
            'size' => self::n(10000, 90000000),
            'width' => self::n(400, 4000),
            'height' => self::n(300, 3000),
            'duration' => $type === 'video' ? self::n(5, 900) : '0',
            'data' => json_encode([
                'variants' => $variants,
                'exif' => ['camera' => 'X100', 'lens' => '23mm', 'iso' => 400, 'gps' => [55.75, 37.61]],
                'colors' => ['#aabbcc', '#112233', '#ffeedd', '#000000'],
                'faces' => [['x' => 0.1, 'y' => 0.2, 'w' => 0.1, 'h' => 0.1]],
                'source' => ['name' => 'upload', 'ua' => 'Mozilla/5.0', 'ts' => 1700000000 + $id],
            ]),
            'hash' => md5((string) $id),
            'status' => '1',
            'created_at' => self::date(),
        ];
        if ($type === 'video') {
            $row['poster'] = json_encode(['url' => "https://cdn.example.com/u/$id/poster.jpg", 'w' => 1280, 'h' => 720]);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private static function post(int $id): array
    {
        $userId = mt_rand(1, 40);
        $row = [
            'id' => (string) $id,
            'user_id' => (string) $userId,
            'editor_id' => $id % 3 ? '0' : (string) ($userId + 1),
            'category_id' => self::n(1, 12),
            'blog_id' => '0',
            'type' => 'article',
            'status' => (string) ($id % 5 ? 1 : 0),
            'title' => '  Post title ' . $id . ' ' . self::text(5),
            'subtitle' => self::text(8),
            'slug' => 'post-' . $id,
            'lead' => '<p>' . self::text(30) . '</p>',
            'body' => '<p>' . self::text(300) . '</p>',
            'body_format' => 'html',
            'cover_id' => (string) (5000 + $id),
            'preview_id' => (string) (7000 + $id),
            'lang' => 'ru',
            'flags' => self::n(0, 31),
            'options' => json_encode(['layout' => 'wide', 'toc' => true, 'ads' => ['top' => 1, 'bottom' => 0]]),
            'meta' => json_encode(['seo_title' => 'SEO ' . $id, 'seo_description' => self::text(12), 'og_image' => "og/$id.jpg", 'marks' => ['hot' => 1, 'new' => 0]]),
            'keywords' => 'php,orm,perf,' . $id,
            'source_url' => 'https://source.example.com/' . $id,
            'source_title' => 'Source ' . $id,
            'views' => self::n(0, 100000),
            'likes' => self::n(0, 3000),
            'dislikes' => self::n(0, 100),
            'rating' => self::n(0, 100) . '.5',
            'comments_count' => self::n(0, 300),
            'bookmarks_count' => self::n(0, 500),
            'shares_count' => self::n(0, 500),
            'reading_time' => self::n(1, 20),
            'words_count' => self::n(100, 5000),
            'is_ad' => '0',
            'is_pinned' => (string) ($id % 2),
            'is_draft' => '0',
            'is_locked' => '0',
            'age_limit' => '0',
            'region_id' => self::n(1, 80),
            'city_id' => self::n(1, 900),
            'geo_lat' => '55.7558',
            'geo_lng' => '37.6173',
            'layout' => 'default',
            'template' => 'article',
            'sort' => (string) $id,
            'score' => '12.5',
            'hot_score' => '0.75',
            'version' => '3',
            'hash' => md5('post' . $id),
            'ip' => '10.0.0.' . ($id % 250),
            'user_agent' => 'Mozilla/5.0',
            'internal_note' => 'note ' . $id,
            'moderated_by' => '0',
            'moderated_at' => null,
            'published_on' => '2026-05-' . sprintf('%02d', $id % 28 + 1),
            'published_at' => self::date(),
            'scheduled_at' => null,
            'edited_at' => self::date(),
            'created_at' => self::date(),
            'updated_at' => self::date(),
            'deleted_at' => null,
            'extra_data' => json_encode(['a' => 1]),
        ];

        $row['author'] = self::user($userId);
        if ($row['editor_id'] !== '0') {
            $row['editor'] = self::user($userId + 1);
        }
        $row['category'] = ['id' => $row['category_id'], 'parent_id' => '0', 'name' => 'Cat', 'slug' => 'cat', 'position' => '1'];
        $row['cover'] = self::image(5000 + $id, $userId, 6);
        for ($k = 0; $k < 5; $k++) {
            $tagId = mt_rand(1, 60);
            $row['tags'][] = [
                'id' => (string) $tagId,
                'name' => 'tag' . $tagId,
                'slug' => 'tag-' . $tagId,
                'title' => 'Tag ' . $tagId,
                'description' => self::text(6),
                'posts_count' => self::n(1, 900),
                'color' => '#ff0000',
                'flags' => self::n(0, 3),
                // Как в OnRetrieved::fillRowsPivot: @pivot — модель pivot-строки
                '@pivot' => new PostTag(['id' => (string) ($id * 10 + $k), 'post_id' => (string) $id, 'tag_id' => (string) $tagId, 'position' => (string) $k, 'is_main' => $k ? '0' : '1']),
            ];
        }
        for ($k = 0; $k < 3; $k++) {
            $sid = $id * 10 + $k;
            $row['selections'][] = [
                'id' => (string) $sid, 'post_id' => (string) $id, 'title' => 'Selection ' . $sid, 'slug' => 'sel-' . $sid,
                'cover_id' => (string) (9000 + $sid), 'position' => (string) $k, 'items_count' => self::n(1, 50),
                'status' => '1', 'created_at' => self::date(),
                'cover' => self::image(9000 + $sid, $userId, 3),
            ];
        }
        $types = ['image', 'image', 'video', 'text', 'image', 'image'];
        foreach ($types as $k => $type) {
            $aid = $id * 10 + $k;
            $attach = [
                'id' => (string) $aid, 'post_id' => (string) $id, 'type' => $type,
                'upload_id' => $type === 'text' ? '0' : (string) (20000 + $aid), 'position' => (string) $k,
                'caption' => self::text(6), 'source' => 'upload', 'meta' => json_encode(['align' => 'center']),
                'created_at' => self::date(),
            ];
            if ($type !== 'text') {
                $attach['upload'] = self::upload(20000 + $aid, $type);
            }
            $row['attaches'][] = $attach;
        }
        $row['preview'] = [
            'id' => (string) (7000 + $id), 'post_id' => (string) $id, 'image_id' => (string) (8000 + $id),
            'title' => 'Preview ' . $id, 'text' => self::text(15), 'crop' => json_encode(['x' => 0, 'y' => 0, 'w' => 1200, 'h' => 630]),
            'created_at' => self::date(),
            'image' => self::image(8000 + $id, $userId, 4),
        ];
        $comments = $id % 4;
        for ($k = 0; $k < $comments; $k++) {
            $cid = $id * 10 + $k;
            $cu = mt_rand(1, 40);
            $row['comments'][] = [
                'id' => (string) $cid, 'post_id' => (string) $id, 'user_id' => (string) $cu, 'parent_id' => '0',
                'body' => self::text(25), 'rating' => self::n(-5, 40), 'status' => '1', 'created_at' => self::date(),
                'author' => self::user($cu),
            ];
        }
        return $row;
    }
}
