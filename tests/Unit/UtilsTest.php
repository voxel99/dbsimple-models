<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use Jam\Models\Model;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Utils\CamelCase;
use Jam\Models\Utils\Strings;
use PHPUnit\Framework\TestCase;

final class UtilsTest extends TestCase
{
    public function testReSplitOutBracesIsUtf8Safe(): void
    {
        $this->assertSame(
            ["a[x = 'при,вет']", 'b(1,2)', 'c'],
            Strings::reSplitOutBraces("a[x = 'при,вет'], b(1,2), c", ',')
        );
    }

    public function testReSplitWithSymmetricBraces(): void
    {
        $this->assertSame(
            ['posts^a,b^', 'tags'],
            Strings::reSplitOutBraces('posts^a,b^, tags', ',', ['(', '[', '^'], [')', ']', '^'])
        );
    }

    public function testTranslit(): void
    {
        $this->assertSame('privet-mir-2024', Strings::translit('Привет, мир! 2024'));
        $this->assertSame('hello_world', Strings::translit('Hello World', '_'));
    }

    public function testCamelCase(): void
    {
        $this->assertSame('PasswordHash', CamelCase::to('password_hash'));
        $this->assertSame('blog_post', CamelCase::from('BlogPost'));
    }

    public function testArrayHelpers(): void
    {
        $this->assertSame([0 => 'a', 2 => 'c'], ArrayHelper::positiveKeys([-1 => 'x', 0 => 'a', 2 => 'c']));
        $this->assertSame('a: 1, b: [1,2]', ArrayHelper::toDescription(['a' => 1, 'b' => [1, 2]]));
        $this->assertSame(['id', 'name'], ArrayHelper::stringCommasToArray(' id, ,name '));
    }

    public function testDbEsc(): void
    {
        $this->assertSame('`id`', Model::dbEsc('id'));
        $this->assertSame('`p`.`id` DESC', Model::dbEsc('p.id DESC'));
        $this->assertSame('FIELD(id, 3, 1)', Model::dbEsc('FIELD(id, 3, 1)'));
    }
}
