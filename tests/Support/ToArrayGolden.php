<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Support;

use Jam\Models\Model;
use Jam\Models\ModelList;
use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Fixtures\Perf\PerfData;
use Jam\Models\Tests\Fixtures\Perf\Post as PerfPost;

/**
 * Сценарий golden-сравнения toArray() с 1dc70de (см. ToArrayGolden1dc70deTest).
 *
 * Использует только API, которое есть в 1dc70de: снимок снимается скриптом
 * tests/Support/make_golden_1dc70de.php на исходниках той версии.
 */
final class ToArrayGolden
{
    public const string FILE = __DIR__ . '/../Fixtures/golden_1dc70de.json';

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        $snap = [];
        $db = TestDatabase::connect();
        Model::initDbSimple([Model::DB_MASTER => $db]);

        // Гидрация из массивов: прод-подобные фикстуры, pivot-отношение tags (@pivot — модель PostTag)
        $rows = PerfData::posts(1);
        $list = new ModelList(PerfPost::class, $rows);
        // В 1dc70de флаги одного узла with() дедуплицировались нестрого (array_unique), и флаг,
        // равный предыдущему при ==, терялся: из tags:p(...):c пропадал c, из tags:c:p — p.
        // Такие сочетания здесь не сравниваются: с тех пор учитываются все флаги
        $with = (new PerfPost())->with(str_replace('tags:p(position|is_main):c', 'tags:p(position|is_main)', PerfData::WITH))->getWithArray(true);
        $snap['perf default'] = $list->toArray();
        $snap['perf :dc with'] = $list->toArray(':dc', null, $with);
        // Для вариантов pivot-отношения сравнивается только оно
        $snap['perf :dp tags'] = array_column($list->toArray(':dp'), 'tags');
        foreach (['tags', 'tags:c', 'tags:p', 'tags:p(position)', 'tags:p(position|is_main)', 'selections.cover,tags'] as $expr) {
            $w = (new PerfPost())->with($expr)->getWithArray(true);
            $snap["perf with $expr tags"] = array_column($list->toArray(null, null, $w), 'tags');
        }

        // Явно присвоенный пустой hasMany
        $user = new User(['id' => 1, 'name' => 'A']);
        $user->posts = [];
        $snap['assigned [] hasMany'] = $user->toArray();
        $user = new User(['id' => 1, 'name' => 'A']);
        $user->posts = new ModelList(Post::class);
        $snap['assigned ModelList hasMany'] = $user->toArray();

        // Загрузка из БД: pivot-отношение Post.tags через post_tag и пустые результаты with()
        $pdo = $db->getPdo();
        $pdo->exec("INSERT INTO users (id, email, name, created_at) VALUES
            (1, 'alice@x.io', 'Alice', '2026-01-01 00:00:00'),
            (2, 'bob@x.io', 'Bob', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO profiles (id, user_id, bio) VALUES (1, 1, 'Alice bio')");
        $pdo->exec("INSERT INTO posts (id, user_id, title, created_at) VALUES
            (1, 1, 'A1', '2026-01-02 00:00:00'),
            (2, 1, 'A2', '2026-01-03 00:00:00'),
            (3, 9, 'X1', '2026-01-04 00:00:00')");
        $pdo->exec("INSERT INTO tags (id, name) VALUES (1, 'php'), (2, 'orm')");
        $pdo->exec("INSERT INTO post_tag (id, post_id, tag_id) VALUES (1, 1, 1), (2, 1, 2), (3, 3, 2)");

        foreach (['tags', 'tags:c', 'tags:p', 'tags:p(tag_id)', 'tags,author'] as $expr) {
            $snap["db Post with $expr"] = Post::instance()->with($expr)->orderBy('id')->all()->toArray();
        }
        $snap['db User with posts,profile'] = User::instance()->with('posts,profile')->orderBy('id')->all()->toArray();

        Model::initDbSimple([]);
        return $snap;
    }
}
