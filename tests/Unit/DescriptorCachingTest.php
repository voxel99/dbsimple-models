<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\Cast\CastClosure;
use Jam\Models\Cast\CastJson;
use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\ModelList;
use Jam\Models\Tests\Fixtures\Casts\MoneyCast;
use Jam\Models\Tests\Fixtures\Perf\Attach;
use Jam\Models\Tests\Fixtures\Perf\Post;
use Jam\Models\Tests\Fixtures\Perf\PostTag;
use Jam\Models\Tests\Fixtures\Perf\UploadImage;
use Jam\Models\Tests\Fixtures\Perf\UploadVideo;
use Jam\Models\Tests\Support\ModelTestCase;

/**
 * Семантика, которую затрагивают кеши описаний моделей (поля, computed, касты, отношения, флаги):
 * кеш по классу не должен смешивать разные классы и не должен игнорировать то, что задано
 * на конкретном экземпляре.
 */
final class DescriptorCachingTest extends ModelTestCase
{
    public function testParentAndChildKeepTheirOwnDescriptions(): void
    {
        // Порядок важен: сначала потомок, потом родитель, потом снова потомок
        $child = new CacheChild(['id' => 1, 'a' => '5', 'b' => 'x', 'meta' => '{"k":1}']);
        $parent = new CacheParent(['id' => 2, 'a' => '7']);
        $child2 = new CacheChild(['id' => 3, 'a' => '9', 'b' => 'y']);

        $this->assertSame(['id', 'a'], $parent->getFields());
        $this->assertSame(['id', 'a', 'b', 'meta'], $child->getFields());

        // Касты различаются: у родителя a — int, у потомка — string
        $this->assertSame(7, $parent->a);
        $this->assertSame('5', $child->a);
        $this->assertEquals((object) ['k' => 1], $child->meta);

        // computed: у потомка добавлено поле y
        $this->assertSame(['id' => 2, 'a' => 7, 'x' => 'x7'], $parent->toArray(':c'));
        $this->assertSame(['id' => 3, 'a' => '9', 'b' => 'y', 'x' => 'x9', 'y' => 'yy'], $child2->toArray(':c'));

        // Поле потомка у родителя неизвестно
        $this->expectException(ModelException::class);
        $parent->b = 'nope';
    }

    public function testChildRelationsDoNotLeakToParent(): void
    {
        $child = new CacheChild(['id' => 1, 'friend' => ['id' => 2, 'a' => '3']]);
        $this->assertInstanceOf(CacheParent::class, $child->friend);
        $this->assertSame(3, $child->friend->a);

        $parent = new CacheParent(['id' => 5]);
        $this->assertFalse($parent->isRelationProp('friend'));
        $this->expectException(ModelException::class);
        $parent->friend = ['id' => 1];
    }

    public function testInstanceFieldListsAreNotShared(): void
    {
        $cut = new CacheParent(['id' => 1, 'a' => '2']);
        $cut->cutFields('id');
        $full = new CacheParent(['id' => 3, 'a' => '4']);

        $full->a = 5;
        $this->assertSame(5, $full->a);
        try {
            $cut->a = 6;
            $this->fail('cutFields() must restrict the instance fields');
        } catch (ModelException) {
        }
        // Обычный экземпляр после «урезанного» по-прежнему принимает все поля
        $again = new CacheParent(['id' => 7, 'a' => '8']);
        $this->assertSame(8, $again->a);

        $excluded = new CacheParent(['id' => 9, 'a' => '10']);
        $excluded->excludeFields('a');
        $this->assertSame(['id' => 9], $excluded->toArray());
        $this->assertSame(['id' => 7, 'a' => 8], $again->toArray());
    }

    public function testInstanceSpecificComputedList(): void
    {
        $plain = new CacheDynamicComputed(false, ['id' => 1]);
        $extended = new CacheDynamicComputed(true, ['id' => 2]);
        $plain2 = new CacheDynamicComputed(false, ['id' => 3]);

        $this->assertSame(['id' => 1, 'x' => 'x'], $plain->toArray(':c'));
        $this->assertSame(['id' => 2, 'x' => 'x', 'y' => 'y'], $extended->toArray(':c'));
        $this->assertSame(['id' => 3, 'x' => 'x'], $plain2->toArray(':c'));
    }

    public function testPolymorphicRelationClassDependsOnRow(): void
    {
        $image = new Attach(['id' => 1, 'type' => 'image', 'upload' => ['id' => 10, 'width' => '4', 'height' => '2']]);
        $video = new Attach(['id' => 2, 'type' => 'video', 'upload' => ['id' => 11, 'duration' => '5']]);
        $this->assertInstanceOf(UploadImage::class, $image->upload);
        $this->assertInstanceOf(UploadVideo::class, $video->upload);
        $this->assertSame(2.0, $image->upload->ratio);

        // Присваивание массива уже созданной модели тоже выбирает класс по строке
        $text = new Attach(['id' => 3, 'type' => 'text']);
        $text->upload = ['id' => 12];
        $this->assertNull($text->upload);
        $text->type = 'video';
        $text->upload = ['id' => 13];
        $this->assertInstanceOf(UploadVideo::class, $text->upload);
    }

    public function testPivotFieldIsHydratedIntoPivotModel(): void
    {
        $post = new Post(['id' => 1]);
        $post->{Model::PIVOT_FIELD} = ['post_id' => '1', 'tag_id' => '2', 'is_main' => '1'];
        $pivot = $post->{Model::PIVOT_FIELD};
        $this->assertInstanceOf(PostTag::class, $pivot);
        $this->assertSame(2, $pivot->tag_id);
        $this->assertTrue($pivot->is_main);

        // У Tag нет pivot-отношения: сырой @pivot остаётся объектом, как и раньше
        $list = new ModelList(Post::class, [['id' => 1, 'tags' => [['id' => 5, '@pivot' => ['position' => '3']]]]]);
        $this->assertInstanceOf(\stdClass::class, $list[0]->tags[0]->{Model::PIVOT_FIELD});
    }

    public function testRelationAliasPrecedenceMatchesGetAllRelations(): void
    {
        $model = new CacheParent();
        $model->setRelations('hasMany', ['dup' => new \Jam\Models\Relation(CacheParent::class, 'dup', 'hasMany', 'id', 'a')]);
        $model->setRelations('belongs', ['dup' => new \Jam\Models\Relation(CacheChild::class, 'dup', 'belongs', 'a', 'id')]);
        $this->assertSame($model->getAllRelations()['dup'], $model->getRelationByAlias('dup'));
        $this->assertSame('belongs', $model->getRelationByAlias('dup')->type());
        $this->assertNull($model->getRelationByAlias('missing'));
        $this->assertNull($model->getRelationByAlias(5));
    }

    public function testSharedCastInstances(): void
    {
        $json = (object) ['type' => 'json', 'rule' => null];
        $first = CastClosure::sharedForField(CacheParent::class, 'meta', $json);
        $this->assertInstanceOf(CastJson::class, $first);
        $this->assertSame($first, CastClosure::sharedForField(CacheParent::class, 'meta', $json));
        // Другое описание того же поля — новый экземпляр
        $other = CastClosure::sharedForField(CacheParent::class, 'meta', (object) ['type' => 'json', 'rule' => null]);
        $this->assertNotSame($first, $other);
        // fromCast() по-прежнему создаёт новый объект на каждый вызов
        $this->assertNotSame(CastClosure::fromCast($json), CastClosure::fromCast($json));
        // Пользовательский каст не переиспользуется
        $money = (object) ['type' => CastClosure::TYPE_CLOSURE, 'rule' => MoneyCast::class];
        $this->assertNotSame(
            CastClosure::sharedForField(CacheParent::class, 'price', $money),
            CastClosure::sharedForField(CacheParent::class, 'price', $money)
        );
        // Скалярные типы — без объекта каста
        $this->assertNull(CastClosure::sharedForField(CacheParent::class, 'a', (object) ['type' => 'int', 'rule' => null]));
    }

    public function testCastRegistryChangeIsHonoured(): void
    {
        $cast = (object) ['type' => 'cache_test_money', 'rule' => null];
        $this->assertNull(CastClosure::sharedForField(CacheParent::class, 'sum', $cast));
        CastClosure::$factoryClasses['cache_test_money'] = MoneyCast::class;
        try {
            $this->assertInstanceOf(MoneyCast::class, CastClosure::sharedForField(CacheParent::class, 'sum', $cast));
        } finally {
            unset(CastClosure::$factoryClasses['cache_test_money']);
        }
        $this->assertNull(CastClosure::sharedForField(CacheParent::class, 'sum', $cast));
    }

    public function testChangeHooksAndGuardOnHotPath(): void
    {
        $model = new CacheHooks(['id' => 1, 'name' => '  Bob ', 'secret' => 's1']);
        // change* вызывается и при гидрации, получает старое значение
        $this->assertSame('bob', $model->name);
        $this->assertSame(['name:' => 'bob'], $model->log[0]);
        $model->name = ' ALICE ';
        $this->assertSame('alice', $model->name);
        $this->assertSame(['name:bob' => 'alice'], $model->log[1]);
        // changeThis вызывается на каждое присваивание
        $this->assertSame(['id', 'name', 'secret', 'name'], $model->changed);

        // guarded: гидрация пишет, снаружи — исключение, изнутри change*-метода — можно
        $this->assertSame('s1', $model->secret);
        $model->rotate = 'x';
        $this->assertSame('rotated', $model->secret);
        $this->expectException(ModelException::class);
        $model->secret = 'hack';
    }

    public function testDynamicWithOnInstanceIsRespected(): void
    {
        $post = new Post(['id' => 1, 'slug' => 's', 'tags' => [['id' => 2, 'name' => 'n', '@pivot' => new PostTag(['position' => 4])]]]);
        $this->assertArrayNotHasKey('share_url', $post->toArray());
        $post->with('share_url');
        $arr = $post->toArray();
        $this->assertSame('https://example.com/p/1-s', $arr['share_url']);

        $post->with('tags:p(position)');
        $this->assertSame(['position' => 4], $post->toArray()['tags'][0][Model::PIVOT_FIELD]);
    }

    public function testOverriddenGetFlagIsUsedByToArray(): void
    {
        $model = new CacheForcedComputed(['id' => 1]);
        // Модель принудительно включает computed-поля через getFlag()
        $this->assertSame(['id' => 1, 'x' => 'x'], $model->toArray([]));
    }

    public function testOverriddenRelationMethodsAreHonoured(): void
    {
        // getAllRelations() дополняет отношения динамически
        $model = new CacheDynamicRelations(['id' => 1, 'extra_rel' => ['id' => 2, 'a' => '3']]);
        $this->assertNotNull($model->getRelationByAlias('extra_rel'));
        $this->assertInstanceOf(CacheParent::class, $model->extra_rel);

        // getRelationClasses() подменяет класс при присваивании массива
        $mapped = new CacheMappedRelations(['id' => 1]);
        $mapped->friend = ['id' => 2, 'b' => 'z'];
        $this->assertInstanceOf(CacheChild::class, $mapped->friend);
    }

    public function testJsonTopLevelKeysPerClass(): void
    {
        $child = new CacheChild(['id' => 1]);
        $child->k = 5;
        $this->assertEquals((object) ['k' => 5], $child->meta);
        $parent = new CacheParent(['id' => 1]);
        $this->expectException(ModelException::class);
        $parent->k = 5;
    }
}

class CacheParent extends Model
{
    protected string $fields = 'id, a';
    protected string $computed = 'x';
    protected $types = ['int' => 'id, a'];

    public function x(): string
    {
        return 'x' . $this->a;
    }
}

class CacheChild extends CacheParent
{
    protected string $fields = 'id, a, b, meta';
    protected string $computed = 'x, y';
    protected $types = ['int' => 'id', 'json' => 'meta(k)'];

    public function __construct($data = null)
    {
        $this->belongs(CacheParent::class, 'friend', 'a', 'id');
        parent::__construct($data);
    }

    public function y(): string
    {
        return 'yy';
    }
}

class CacheDynamicComputed extends Model
{
    protected string $fields = 'id';

    public function __construct(bool $extended, $data = null)
    {
        $this->computed = $extended ? 'x, y' : 'x';
        parent::__construct($data);
    }

    public function x(): string
    {
        return 'x';
    }

    public function y(): string
    {
        return 'y';
    }
}

class CacheHooks extends Model
{
    protected string $fields = 'id, name, secret';
    protected string $guarded = 'secret';
    protected string $extra = 'rotate';
    protected $types = ['int' => 'id'];

    /** @var array<int, array<string, string>> */
    public array $log = [];
    /** @var array<int, string> */
    public array $changed = [];

    public function changeName($new, $old)
    {
        $value = strtolower(trim((string) $new));
        $this->log[] = ['name:' . $old => $value];
        return $value;
    }

    public function changeThis($prop): void
    {
        $this->changed[] = $prop;
    }

    public function __set($prop, $value)
    {
        if ($prop === 'rotate') {
            // Внутренний метод модели может писать guarded-поле через fromArray()
            $this->fromArray(['secret' => 'rotated']);
            return;
        }
        parent::__set($prop, $value);
    }
}

class CacheForcedComputed extends Model
{
    protected string $fields = 'id';
    protected string $computed = 'x';

    public function x(): string
    {
        return 'x';
    }

    public function getFlag($flags, $letter)
    {
        return $letter === self::FLAG_COMPUTABLE ? true : ($flags[$letter] ?? false);
    }
}

class CacheDynamicRelations extends Model
{
    protected string $fields = 'id';

    public function getAllRelations(): array
    {
        return parent::getAllRelations() + [
            'extra_rel' => new \Jam\Models\Relation(CacheParent::class, 'extra_rel', 'belongs', 'id', 'id'),
        ];
    }
}

class CacheMappedRelations extends Model
{
    protected string $fields = 'id';

    public function __construct($data = null)
    {
        $this->belongs(CacheParent::class, 'friend', 'id', 'id');
        parent::__construct($data);
    }

    public function getRelationClasses()
    {
        $classes = parent::getRelationClasses();
        $classes['friend'] = fn() => CacheChild::class;
        return $classes;
    }
}
