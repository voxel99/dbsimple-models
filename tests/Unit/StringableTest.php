<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\Tests\Fixtures\Models\Article;
use Jam\Models\Tests\Support\ModelTestCase;

final class StringableTest extends ModelTestCase
{
    public function testToStringAndFromString(): void
    {
        $this->assertSame('published', Article::toString('status', Article::STATUS_PUBLISHED));
        $this->assertSame(Article::STATUS_DRAFT, Article::fromString('status', 'draft'));
        $this->assertNull(Article::fromString('status', 'unknown'));
        $this->assertTrue(Article::hasString('status', 'published'));
        $this->assertFalse(Article::hasString('status', 'nope'));
    }

    public function testUnknownValueThrowsWithDescription(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("All values: [-1: banned, 0: draft, 1: published]");
        Article::toString('status', 42);
    }

    public function testNegativeKeysCanBeHidden(): void
    {
        $this->assertSame([-1 => 'banned', 0 => 'draft', 1 => 'published'], Article::stringsType('status', false));
        $this->assertSame([0 => 'draft', 1 => 'published'], Article::stringsType('status', true));
        $this->assertFalse(Article::hasString('status', 'banned', true));
    }

    public function testModelAcceptsStringCodesAndExportsThemWithFlag(): void
    {
        $article = new Article(['id' => 1, 'status' => 'published']);
        $this->assertSame(Article::STATUS_PUBLISHED, $article->status);
        $this->assertSame(1, $article->toArray()['status']);
        $this->assertSame('published', $article->toArray(':s')['status']);
    }

    public function testStringifyAndDestringify(): void
    {
        $this->assertSame(['id' => 1, 'status' => 'draft'], Article::stringify(['id' => 1, 'status' => 0]));
        $this->assertSame(['id' => 1, 'status' => 0], Article::destringify(['id' => 1, 'status' => 'draft']));
    }

    public function testDescriptions(): void
    {
        $this->assertSame(
            ['banned' => 'Banned by moderator', 'draft' => 'Draft', 'published' => 'Published'],
            Article::stringsDescription('status', '', false)
        );
        $this->assertSame('draft: Draft, published: Published', Article::stringsDescriptionLine('status', '', true));
    }

    public function testIntable(): void
    {
        $this->assertSame(30, Article::toInt('priority', 'high'));
        $this->assertSame('normal', Article::fromInt('priority', 20));
        $this->assertSame('fallback', Article::fromInt('priority', 99, 'fallback'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All values: [low: 10, normal: 20, high: 30]');
        Article::toInt('priority', 'urgent');
    }
}
