<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\ModelList;
use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\Tag;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\ModelTestCase;

final class ModelAttributesTest extends ModelTestCase
{
    public function testConstructFromArrayAndObject(): void
    {
        $fromArray = new Tag(['id' => 1, 'name' => 'php']);
        $fromObject = new Tag((object) ['id' => 1, 'name' => 'php']);

        $this->assertSame(1, $fromArray->id);
        $this->assertSame('php', $fromArray->name);
        $this->assertSame($fromArray->toArray(), $fromObject->toArray());
    }

    public function testTableNameDefaultsToSnakeCaseClassName(): void
    {
        $model = new class extends Model {
            protected string $fields = 'id';
        };
        // Для анонимного класса имя не информативно — проверяем на обычной модели без $table
        $this->assertSame('users', (new User())->table());
        $this->assertSame('blog_post', (new BlogPost())->table());
        $this->assertSame('id', $model->pk());
    }

    public function testUnknownFieldThrows(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('[nope] is not a correct field');
        new Tag(['nope' => 1]);
    }

    public function testSilentFromArrayIgnoresUnknownFields(): void
    {
        $tag = new Tag();
        $previous = ini_set('error_log', '/dev/null');
        try {
            $tag->fromArray(['name' => 'php', 'nope' => 1], true);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertSame(['name' => 'php'], $tag->toArray());
    }

    public function testModelWithoutFieldsAcceptsAnything(): void
    {
        $model = new class (['anything' => 1]) extends Model {
        };
        $this->assertSame(1, $model->anything);
    }

    public function testReservedFieldNamesAreRejected(): void
    {
        $this->expectException(ModelException::class);
        new class extends Model {
            protected string $fields = 'id, table';
        };
    }

    public function testRelationAliasMustNotClashWithField(): void
    {
        $this->expectException(ModelException::class);
        new class extends Model {
            protected string $fields = 'id, author';

            public function __construct($data = null)
            {
                $this->belongs(User::class, 'author', 'user_id', 'id');
                parent::__construct($data);
            }
        };
    }

    public function testRelationAliasMustNotClashWithComputed(): void
    {
        // Иначе данные из with() молча терялись бы: присваивание computed-свойству игнорируется
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('Relation alias and computed property have equal names: author');
        new class extends Model {
            protected string $fields = 'id, user_id';
            protected string $computed = 'author';

            public function __construct($data = null)
            {
                $this->belongs(User::class, 'author', 'user_id', 'id');
                parent::__construct($data);
            }

            public function author(): string
            {
                return '';
            }
        };
    }

    public function testRelationLoadedState(): void
    {
        $user = new User(['id' => 1]);
        $this->assertFalse($user->isRelationLoaded('posts'));

        $user->posts = [['id' => 1, 'title' => 'A']];
        $this->assertTrue($user->isRelationLoaded('posts'));

        unset($user->posts);
        $this->assertFalse($user->isRelationLoaded('posts'));

        // Пустые данные связи в массиве инициализации: связь загружена, но пуста
        $user = new User(['id' => 1, 'posts' => [], 'profile' => null]);
        $this->assertTrue($user->isRelationLoaded('posts'));
        $this->assertNull($user->posts);
        $this->assertFalse(isset($user->posts));
        $this->assertTrue($user->isRelationLoaded('profile'));
        $this->assertNull($user->profile);
        $this->assertSame(['id' => 1], $user->toArray());

        // Ручное присваивание связи (в т.ч. null) помечает её загруженной; поля — нет
        $user = new User(['id' => 1]);
        $user->profile = null;
        $this->assertTrue($user->isRelationLoaded('profile'));
        $user->name = 'A';
        $this->assertFalse($user->isRelationLoaded('name'));

        $clone = clone $user;
        $this->assertTrue($clone->isRelationLoaded('profile'));
        $this->assertFalse($clone->isRelationLoaded('posts'));
    }

    /**
     * Пустое значение одиночной связи на любом пути гидрации/присваивания
     * @return array<string, array{\Closure(string, mixed): Model}>
     */
    public static function singleRelationPaths(): array
    {
        return [
            'constructor array' => [static fn (string $class, string $alias, $empty) => new $class(['id' => 1, $alias => $empty])],
            'constructor object' => [static fn (string $class, string $alias, $empty) => new $class((object) ['id' => 1, $alias => $empty])],
            'assignment' => [static function (string $class, string $alias, $empty) {
                $model = new $class(['id' => 1]);
                $model->$alias = $empty;
                return $model;
            }],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('singleRelationPaths')]
    public function testEmptySingleRelationValueMeansNoRelation(\Closure $make): void
    {
        // hasOne (User.profile) и belongs (Post.author); [] и пустой объект
        foreach ([[User::class, 'profile'], [Post::class, 'author']] as [$class, $alias]) {
            foreach (['[]' => [], '{}' => new \stdClass()] as $label => $empty) {
                $model = $make($class, $alias, $empty);
                $this->assertNull($model->$alias, "$class::$alias = $label");
                $this->assertFalse(isset($model->$alias), "$class::$alias = $label");
                $this->assertTrue($model->isRelationLoaded($alias), "$class::$alias = $label");
                $this->assertArrayNotHasKey($alias, $model->toArray(), "$class::$alias = $label");
            }
        }

        // Непустое значение — модель связанного класса
        $user = $make(User::class, 'profile', ['bio' => 'hi']);
        $this->assertInstanceOf(\Jam\Models\Tests\Fixtures\Models\Profile::class, $user->profile);
        $this->assertSame('hi', $user->profile->bio);
        $this->assertTrue($user->isRelationLoaded('profile'));
        $user = $make(User::class, 'profile', (object) ['bio' => 'hi']);
        $this->assertSame('hi', $user->profile->bio);
    }

    public function testAssignedEmptyHasManyIsEmptyModelList(): void
    {
        foreach (['[]' => [], 'ModelList' => new ModelList(Post::class)] as $label => $empty) {
            $user = new User(['id' => 1]);
            $user->posts = $empty;
            $this->assertInstanceOf(ModelList::class, $user->posts, $label);
            $this->assertTrue($user->posts->isEmpty(), $label);
            $this->assertTrue($user->isRelationLoaded('posts'), $label);
            // Явно присвоенный пустой список выводится как []
            $this->assertSame(['id' => 1, 'posts' => []], $user->toArray(), $label);
        }
    }

    public function testGuardedFieldCannotBeAssignedButCanBeHydrated(): void
    {
        $user = new User(['password_hash' => 'hydrated-from-db']);
        $this->assertSame('hydrated-from-db', $user->password_hash);

        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('password_hash is guarded');
        $user->password_hash = 'hacked';
    }

    public function testGuardedFieldCanBeWrittenFromInsideModel(): void
    {
        $user = (new User())->setPassword('secret');
        $this->assertTrue($user->checkPassword('secret'));
        $this->assertFalse($user->checkPassword('wrong'));
    }

    public function testChangeMutatorIsCalledOnAssignment(): void
    {
        $user = new User(['email' => '  Alice@Example.COM ']);
        $this->assertSame('alice@example.com', $user->email);

        $user->email = 'BOB@example.com';
        $this->assertSame('bob@example.com', $user->email);
    }

    public function testChangeMutatorMustReturnValue(): void
    {
        $model = new class extends Model {
            protected string $fields = 'id, title';

            public function changeTitle($new, $old)
            {
                // забыли return
            }
        };
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('must return a value');
        $model->title = 'x';
    }

    public function testHiddenFieldIsNotExported(): void
    {
        $user = new User(['email' => 'a@b.c', 'password_hash' => 'hash']);
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
        $this->assertSame('hash', $user->toArray(':h')['password_hash']);
    }

    public function testExtraFieldIsStoredButNeverPersisted(): void
    {
        $user = new User(['email' => 'a@b.c', 'plain_password' => 'secret']);
        $this->assertSame('secret', $user->plain_password);
        $this->assertArrayNotHasKey('plain_password', $user->toArray());
        $this->assertSame('secret', $user->toArray(':x')['plain_password']);
        // Флаги, которыми пользуется insert/update, extra-поля не включают
        $this->assertArrayNotHasKey('plain_password', $user->toArray(':_hu'));
    }

    public function testComputedFieldIsCalculatedLazily(): void
    {
        $user = new User(['name' => 'Alice', 'email' => 'a@b.c']);
        $this->assertSame('Alice <a@b.c>', $user->display_name);
        $this->assertArrayNotHasKey('display_name', $user->toArray());
        $this->assertSame('Alice <a@b.c>', $user->toArray(':c')['display_name']);
    }

    public function testComputedFieldIsRecalculatedAfterSourceChange(): void
    {
        $user = new User(['name' => 'Alice', 'email' => 'a@b.c']);
        $this->assertSame('Alice <a@b.c>', $user->display_name);

        $user->name = 'Bob';
        $this->assertSame('Bob <a@b.c>', $user->display_name, 'computed value must not be stale');
    }

    public function testAssigningComputedFieldIsIgnored(): void
    {
        $user = new User(['name' => 'Alice', 'email' => 'a@b.c']);
        $user->display_name = 'overridden';
        $this->assertSame('Alice <a@b.c>', $user->display_name);
    }

    public function testIssetAndUnset(): void
    {
        $tag = new Tag(['name' => 'php']);
        $this->assertTrue(isset($tag->name));
        $this->assertFalse(isset($tag->id));

        unset($tag->name);
        $this->assertFalse(isset($tag->name));
        $this->assertNull($tag->name);
    }

    public function testNullIsSkippedButNullValueMeansSqlNull(): void
    {
        $this->assertSame(
            ['a' => null],
            Model::convertRecordsBeforeSaveToDb(['a' => Model::NULL_VALUE, 'b' => null])
        );
    }

    public function testCloneIsIndependent(): void
    {
        $a = new Tag(['name' => 'a']);
        $b = clone $a;
        $b->name = 'b';
        $this->assertSame('a', $a->name);
        $this->assertSame('b', $b->name);
    }

    public function testCutAndExcludeFields(): void
    {
        $user = new User(['id' => 1, 'email' => 'a@b.c', 'name' => 'A']);
        $user->excludeFields('name');
        $this->assertNotContains('name', $user->getFields());
        $this->assertSame(['id' => 1, 'email' => 'a@b.c'], $user->toArray());

        $user = new User(['id' => 1, 'email' => 'a@b.c', 'name' => 'A']);
        $user->cutFields('id, name');
        $this->assertSame(['id', 'name'], array_values($user->getFields()));
        $this->assertSame(['id' => 1, 'name' => 'A'], $user->toArray());
    }

    public function testExcludeFieldsOnFreshInstance(): void
    {
        new User(); // первая инстанциация класса инициализирует кэш проверок
        $user = new User();
        $user->excludeFields('settings');
        $this->assertNotContains('settings', $user->getFields());
        $this->assertContains('email', $user->getFields());
    }

    public function testCutFieldsRejectsUnknownField(): void
    {
        $this->expectException(ModelException::class);
        (new User())->cutFields('id, nope');
    }

    public function testGetDiff(): void
    {
        $user = new User(['id' => 1, 'email' => 'a@b.c', 'name' => 'Alice']);
        $this->assertSame([], $user->getDiff(['email' => 'a@b.c', 'name' => 'Alice']));
        $this->assertSame(['name' => 'Alice'], $user->getDiff(['email' => 'a@b.c', 'name' => 'Bob']));
    }

    public function testSerializeRoundTrip(): void
    {
        $post = new Post(['id' => 3, 'title' => 'T', 'flags' => ['pinned' => 1], 'comments' => [['id' => 1, 'body' => 'c']]]);
        /** @var Post $copy */
        $copy = unserialize(serialize($post));

        $this->assertInstanceOf(Post::class, $copy);
        $this->assertEquals($post->toArray(), $copy->toArray());
        $this->assertSame('c', $copy->comments[0]->body);
    }

    public function testNestedRelationDataIsHydratedIntoModels(): void
    {
        $user = new User([
            'id' => 1,
            'profile' => ['bio' => 'hi'],
            'posts' => [['title' => 'A'], ['title' => 'B']],
        ]);

        $this->assertInstanceOf(\Jam\Models\Tests\Fixtures\Models\Profile::class, $user->profile);
        $this->assertInstanceOf(\Jam\Models\ModelList::class, $user->posts);
        $this->assertSame(Post::class, $user->posts->getClass());
        $this->assertSame(['A', 'B'], $user->posts->column('title'));
    }

    public function testEmptyAndExists(): void
    {
        $this->assertTrue((new Tag())->isEmpty());
        $this->assertTrue((new Tag(['name' => 'x']))->isExists());
    }
}

/** @internal */
class BlogPost extends Model
{
    protected string $fields = 'id';
}
