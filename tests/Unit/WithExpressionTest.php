<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\ModelException;
use Jam\Models\Relation;
use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\ModelTestCase;

/**
 * Синтаксис with():
 *   alias[условие](поля)^исключения^?!:флаги.вложенная_связь
 *   ? — связь необязательна (неизвестный алиас не бросает исключение)
 *   ! — вернуть пустой объект/массив вместо отсутствующей связи
 */
final class WithExpressionTest extends ModelTestCase
{
    /** @return array<string, array<string, mixed>> */
    private function parse(string|array $expression): array
    {
        return Post::instance()->with($expression)->getWithArray(true);
    }

    public function testSimpleList(): void
    {
        $this->assertSame(['comments', 'author'], array_keys($this->parse('comments, author')));
        $this->assertSame(['comments', 'author'], array_keys($this->parse(['comments', 'author'])));
    }

    public function testFieldsConditionsAndExclude(): void
    {
        $with = $this->parse('comments[is_approved = 1](id,body)^user_id^');
        $this->assertSame(['id', 'body'], $with['comments']['fields']);
        $this->assertSame(['is_approved = 1'], $with['comments']['where']);
        $this->assertSame(['user_id'], $with['comments']['exclude']);
    }

    public function testCommasInsideBracesDoNotSplitRelations(): void
    {
        $with = $this->parse('comments[id IN (1,2)](id,body)^post_id,user_id^, author(id,name)');
        $this->assertSame(['comments', 'author'], array_keys($with));
        $this->assertSame(['id IN (1,2)'], $with['comments']['where']);
        $this->assertSame(['post_id', 'user_id'], $with['comments']['exclude']);
        $this->assertSame(['id', 'name'], $with['author']['fields']);
    }

    public function testUnicodeInsideConditions(): void
    {
        // «р» в UTF-8 — это D1 80: байт 0x80 раньше использовался как внутренний разделитель
        $with = $this->parse("comments[body = 'Привет, мир'](id,body), author");
        $this->assertSame(['comments', 'author'], array_keys($with));
        $this->assertSame(["body = 'Привет, мир'"], $with['comments']['where']);
    }

    public function testNestedRelations(): void
    {
        $with = User::instance()->with('posts(id,title).comments.author, profile')->getWithArray(true);
        $this->assertSame(['posts', 'profile'], array_keys($with));
        $this->assertSame(['id', 'title'], $with['posts']['fields']);
        $this->assertSame(['comments'], array_keys($with['posts']['with']));
        $this->assertSame(['author'], array_keys($with['posts']['with']['comments']['with']));
    }

    public function testDotInsideConditionIsNotNesting(): void
    {
        $with = User::instance()->with('posts[views > 1.5].comments')->getWithArray(true);
        $this->assertSame(['views > 1.5'], $with['posts']['where']);
        $this->assertSame(['comments'], array_keys($with['posts']['with']));
    }

    public function testSameRelationIsMergedFromSeveralPaths(): void
    {
        $with = User::instance()->with('posts.comments, posts.tags')->getWithArray(true);
        $this->assertSame(['comments', 'tags'], array_keys($with['posts']['with']));
    }

    public function testFlags(): void
    {
        $with = $this->parse('comments:o(id DESC):l(5):k(id), tags:p');
        $this->assertSame(['o' => 'id DESC', 'l' => '5', 'k' => 'id'], $with['comments']['flags']);
        $this->assertSame(['p' => true], $with['tags']['flags']);
    }

    public function testBooleanAndArgumentFlagsCombined(): void
    {
        $with = $this->parse('comments:c:o(id DESC), tags:o(id):p');
        $this->assertSame(['c' => true, 'o' => 'id DESC'], $with['comments']['flags']);
        $this->assertSame(['o' => 'id', 'p' => true], $with['tags']['flags']);
    }

    public function testFlagArgumentsUsePipeAsSeparator(): void
    {
        $with = $this->parse('comments:o(post_id DESC|id)');
        $this->assertSame('post_id DESC,id', $with['comments']['flags']['o']);
    }

    public function testRequiredAndReturnEmptyMarkers(): void
    {
        $with = User::instance()->with('profile?!')->getWithArray(true);
        $this->assertTrue($with['profile']['ret_empty']);
    }

    public function testUnknownAliasThrowsUnlessOptional(): void
    {
        $this->assertSame([], $this->parse('unknown?'));

        $this->expectException(ModelException::class);
        $this->parse('unknown');
    }

    public function testInvalidFlagArgumentThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Param must be integer');
        $this->parse('comments:l(abc)');
    }

    public function testEmptyNestedRelationThrows(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('Empty relation');
        User::instance()->with('posts..comments');
    }

    public function testArraySyntax(): void
    {
        $with = $this->parse([
            'comments' => ['fields' => ['id', 'body'], 'where' => ['is_approved = 1'], 'flags' => ['o' => 'id DESC']],
            'author',
        ]);
        $this->assertSame(['id', 'body'], $with['comments']['fields']);
        $this->assertSame(['is_approved = 1'], $with['comments']['where']);
        $this->assertArrayHasKey('author', $with);
    }

    public function testWithoutRemovesRelations(): void
    {
        $post = Post::instance()->with('comments, author, tags');
        $post->without('author');
        $this->assertSame(['comments', 'tags'], array_keys($post->getWithArray()));
        $post->without();
        $this->assertSame([], $post->getWithArray());
    }

    public function testRelationsAreNotSharedBetweenInstances(): void
    {
        $a = Post::instance()->with('comments(id)');
        $b = Post::instance()->with('comments');

        /** @var Relation $ra */
        $ra = $a->getWithArray()['comments'];
        /** @var Relation $rb */
        $rb = $b->getWithArray()['comments'];
        $this->assertNotSame($ra, $rb);
        $this->assertSame([], $rb->getFields());
    }
}
