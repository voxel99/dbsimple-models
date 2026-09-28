<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\DatabaseTestCase;

final class QueryBuilderTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([['alice', 'Alice'], ['bob', 'Bob'], ['carol', 'Carol']] as [$login, $name]) {
            (new User(['email' => $login . '@x.io', 'name' => $name]))->save();
        }
        $posts = [
            [1, 'Alpha', 10], [1, 'Beta', 30], [2, 'Gamma', 20], [2, 'Delta', 0], [3, 'Epsilon', 5],
        ];
        foreach ($posts as [$userId, $title, $views]) {
            (new Post(['user_id' => $userId, 'title' => $title, 'views' => $views]))->save();
        }
        $this->resetQueryLog();
    }

    public function testWhereWithPlaceholders(): void
    {
        $titles = Post::instance()
            ->where('user_id = ?d', 1)
            ->where('title LIKE ?', 'B%')
            ->column('title');

        $this->assertSame(['Beta'], $titles);
        $this->assertSame(
            "SELECT `title` FROM posts WHERE (user_id = 1) AND (title LIKE 'B%') AND deleted_at IS NULL",
            $this->queries()[0]
        );
    }

    public function testWhereInAndSkippedOptionalBlock(): void
    {
        $this->assertSame([1, 3], Post::instance()->where('id IN (?a)', [1, 3])->orderBy('id')->column('id'));

        // Условный блок {...} DbSimple выпадает, если значение DBSIMPLE_SKIP
        $userId = null;
        $count = Post::instance()->where('1=1 { AND user_id = ?d }', $userId ?? DBSIMPLE_SKIP)->count();
        $this->assertSame(5, $count);
    }

    public function testOrderLimitOffset(): void
    {
        $this->assertSame(['Beta', 'Gamma'], Post::instance()->orderBy('views DESC')->limit(2)->column('title'));
        $this->assertSame(['Gamma', 'Alpha'], Post::instance()->orderBy('views DESC')->limit(2)->offset(1)->column('title'));
        $this->assertSame([2, 1, 3, 4, 5], Post::instance()->orderBy('user_id, views DESC')->column('id'));
    }

    public function testOffsetWithoutLimit(): void
    {
        $this->assertSame([4, 5], Post::instance()->orderBy('id')->offset(3)->column('id'));
    }

    public function testCollectionShortcutArguments(): void
    {
        // collection($limit, $offset) — короткая форма без списка полей
        $this->assertCount(2, Post::instance()->orderBy('id')->collection(2, 1));
        $this->assertSame(['Alpha', 'Beta'], Post::instance()->collection('id, title', 2, 0, 'id')->column('title'));
    }

    public function testAggregates(): void
    {
        $this->assertSame(5, Post::instance()->count());
        $this->assertSame(2, Post::instance()->where('user_id = ?d', 1)->count());
        $this->assertSame(65, Post::instance()->sum('views'));
        $this->assertSame(30, Post::instance()->max('views'));
        $this->assertSame(0, Post::instance()->min('views'));
        $this->assertEquals(13, Post::instance()->avg('views'));
    }

    public function testGroupByIsChainable(): void
    {
        $rows = Post::instance()
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->collectionRaw('user_id, SUM(views) AS total');

        $this->assertEquals(
            [['user_id' => 1, 'total' => 40], ['user_id' => 2, 'total' => 20], ['user_id' => 3, 'total' => 5]],
            $rows
        );
    }

    public function testValueColumnAndKeyedColumn(): void
    {
        $this->assertSame('Beta', Post::instance()->id(2)->value('title'));
        $this->assertSame([1 => 'alice@x.io', 2 => 'bob@x.io', 3 => 'carol@x.io'], User::instance()->column('email', 'id'));
    }

    public function testRawAndSimpleCollections(): void
    {
        $raw = Post::instance()->id(1)->firstRaw();
        $this->assertSame('Alpha', $raw['title']);
        $this->assertEquals(1, $raw['user_id']); // без приведения типов: как вернул драйвер

        $simple = Post::instance()->where('id = ?d', 1)->collectionSimple(false, 'id, views');
        $this->assertSame([['id' => 1, 'views' => 10]], $simple); // с приведением типов
    }

    public function testJoinWithAlias(): void
    {
        $titles = Post::instance()
            ->alias('p')
            ->join('users u ON u.id = p.user_id')
            ->where('u.name = ?', 'Bob')
            ->orderBy('p.id')
            ->collection('p.*')
            ->column('title');

        $this->assertSame(['Gamma', 'Delta'], $titles);
        $this->assertQueryLogContains('FROM posts AS p JOIN users u ON u.id = p.user_id WHERE u.name = \'Bob\' AND p.deleted_at IS NULL');
    }

    public function testJoinPlaceholdersAndLeftJoinRawColumns(): void
    {
        // Колонки из join-таблицы не являются полями модели — забираем их сырыми строками
        $rows = Post::instance()
            ->alias('p')
            ->leftJoin('users u ON u.id = p.user_id AND u.name <> ?', 'Carol')
            ->orderBy('p.id')
            ->collectionRaw('p.id, u.name AS author_name');

        $this->assertSame(['Alice', 'Alice', 'Bob', 'Bob', null], array_column($rows, 'author_name'));
    }

    public function testSubquery(): void
    {
        $popularAuthors = Post::subquery('SELECT user_id FROM posts WHERE views >= ?d', 20);
        $names = User::instance()->where('id IN (?s)', $popularAuthors)->orderBy('id')->column('name');
        $this->assertSame(['Alice', 'Bob'], $names);
    }

    public function testChunk(): void
    {
        $batches = [];
        Post::instance()->chunk(function ($list, int $i) use (&$batches) {
            $batches[$i] = $list->column('id');
        }, 2, null, 'id');

        $this->assertSame([[1, 2], [3, 4], [5]], $batches);
    }

    public function testBuilderStateIsResetAfterQuery(): void
    {
        $builder = Post::instance()->where('user_id = ?d', 1)->limit(1)->orderBy('id DESC');
        $this->assertSame([2], $builder->column('id'));
        // условия, лимит и сортировка сброшены
        $this->assertSame(5, $builder->count());
    }

    public function testPersistentBuilderKeepsConditions(): void
    {
        $builder = Post::instance(true)->where('user_id = ?d', 2);
        $this->assertSame(2, $builder->count());
        $this->assertSame(['Gamma', 'Delta'], $builder->orderBy('id')->column('title'));
        $this->assertSame(20, $builder->sum('views'));
    }

    public function testAggregateKeepsConditionsForPagination(): void
    {
        // Агрегат сбрасывает только себя: условия остаются для следующей выборки страницы
        $builder = Post::instance()->where('user_id = ?d', 1);
        $total = $builder->count();
        $page = $builder->orderBy('id')->limit(1)->column('title');

        $this->assertSame(2, $total);
        $this->assertSame(['Alpha'], $page);
        // ...а после выборки списка состояние уже сброшено
        $this->assertSame(5, $builder->count());
    }

    public function testFailedQueryStillResetsBuilder(): void
    {
        $builder = Post::instance()->where('no_such_column = 1');
        try {
            $builder->column('id');
            $this->fail('Expected SQL error');
        } catch (\RuntimeException) {
        }
        $this->assertSame(5, $builder->count());
    }
}
