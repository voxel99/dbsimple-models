<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\ModelList;
use Jam\Models\Tests\Fixtures\Models\Tag;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\DatabaseTestCase;

/**
 * Возможности, завязанные на диалект MySQL (INSERT IGNORE, ON DUPLICATE KEY UPDATE, блокировки).
 */
final class MysqlDialectTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireMysql();
    }

    public function testInsertIgnore(): void
    {
        (new Tag(['name' => 'php']))->save();
        $id = (new Tag(['name' => 'php']))->save([Model::SAVE_USE_INSERT_IGNORE => true]);

        $this->assertSame(0, $id);
        $this->assertSame(1, Tag::instance()->count());
    }

    public function testInsertOnDuplicateKeyUpdateReturnsExistingId(): void
    {
        (new Tag(['name' => 'php']))->save();
        (new Tag(['name' => 'orm']))->save();

        $tag = new Tag(['name' => 'orm']);
        $id = $tag->save([Model::SAVE_USE_INSERT_DKU => true]);

        $this->assertSame(2, $id);
        $this->assertSame(2, Tag::instance()->count());
    }

    public function testCreateOrUpdate(): void
    {
        $this->assertTrue(Tag::createOrUpdate(['id' => 5, 'name' => 'php']));
        $this->assertFalse(Tag::createOrUpdate(['id' => 5, 'name' => 'php8']));
        $this->assertSame('php8', Tag::instance()->id(5)->value('name'));
    }

    public function testInsertOnDuplicateKeyUpdateRaw(): void
    {
        (new Tag(['name' => 'php']))->save();
        Tag::insertOnDuplicateKeyUpdateRaw([['id' => 1, 'name' => 'php8'], ['id' => 2, 'name' => 'orm']]);

        $this->assertSame([1 => 'php8', 2 => 'orm'], Tag::instance()->orderBy('id')->column('name', 'id'));
    }

    public function testBulkInsertIgnoreViaModelList(): void
    {
        (new Tag(['name' => 'php']))->save();
        (new ModelList(Tag::class, [['name' => 'php'], ['name' => 'sql']]))->insertI();

        $this->assertSame(['php', 'sql'], Tag::instance()->orderBy('id')->column('name'));
    }

    public function testLockForUpdateInTransaction(): void
    {
        (new User(['email' => 'a@x.io']))->save();
        $this->db->transaction();
        $user = User::instance()->id(1)->lockForUpdate()->first();
        $user->name = 'Locked';
        $user->save();
        $this->db->commit();

        $this->assertQueryLogContains('LIMIT 1 FOR UPDATE');
        $this->assertSame('Locked', User::instance()->id(1)->value('name'));
    }

    public function testModelsWorkThroughLazyConnectWrapper(): void
    {
        $url = parse_url((string) getenv('DBSIMPLE_TEST_MYSQL'));
        $connect = new \Jam\DbSimple\Connect(sprintf(
            'mypdo://%s:%s@%s/%s?enc=utf8mb4',
            $url['user'],
            $url['pass'] ?? '',
            $url['host'],
            ltrim($url['path'], '/')
        ));
        $connect->setErrorHandler(static function (string $message): void {
            throw new \RuntimeException($message);
        });
        Model::initDbSimple([Model::DB_MASTER => $connect]);

        (new User(['email' => 'lazy@x.io', 'posts' => [['title' => "It's lazy"]]]))->save();
        $user = User::instance()->with('posts[title = this(email) OR 1=1]')->where('email = ?', 'lazy@x.io')->first();

        $this->assertSame(["It's lazy"], $user->posts->column('title'));
        $this->assertSame("'x'", $user->getDb()->escape('x'));
    }
}
