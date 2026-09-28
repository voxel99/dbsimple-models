<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Unit;

use InvalidArgumentException;
use Jam\Models\Storage\Criteria;
use PHPUnit\Framework\TestCase;

final class CriteriaTest extends TestCase
{
    private function sql(array $conditions): string
    {
        return (new Criteria($conditions))->toSql(
            static fn($v) => is_int($v) || is_float($v) ? (string) $v : "'" . addslashes((string) $v) . "'",
            static fn(string $id) => '`' . $id . '`'
        );
    }

    public function testToSql(): void
    {
        $this->assertSame('`user_id` = 5', $this->sql(['user_id' => 5]));
        $this->assertSame('`status` IN (1, 2)', $this->sql(['status' => [1, 2]]));
        $this->assertSame('`deleted_at` IS NULL', $this->sql(['deleted_at' => null]));
        $this->assertSame('`deleted_at` IS NOT NULL', $this->sql(['deleted_at' => ['!=' => null]]));
        $this->assertSame('`views` >= 10 AND `views` < 100', $this->sql(['views' => ['>=' => 10, '<' => 100]]));
        $this->assertSame("`title` LIKE 'PHP%'", $this->sql(['title' => ['like' => 'PHP%']]));
        $this->assertSame('`p`.`category_id` <> 3', $this->sql(['p.category_id' => ['!=' => 3]]));
        $this->assertSame('`id` NOT IN (1)', $this->sql(['id' => ['not in' => [1]]]));
        $this->assertSame(
            "`a` = 1 AND ((`b` = 2) OR (`c` = 'x' AND `d` IS NULL))",
            $this->sql(['a' => 1, Criteria::OR => [['b' => 2], ['c' => 'x', 'd' => null]]])
        );
    }

    public function testEmptyListsAndConditions(): void
    {
        $this->assertSame('1 = 0', $this->sql(['id' => []]));
        $this->assertSame('1 = 1', $this->sql(['id' => ['not in' => []]]));
        $this->assertSame('1 = 1', $this->sql([]));
        $this->assertSame('1 = 0', $this->sql([Criteria::OR => []]));
    }

    public function testMatches(): void
    {
        $row = ['id' => '5', 'status' => 1, 'title' => 'PHP 8.4', 'deleted_at' => null, 'views' => 42];

        $this->assertTrue((new Criteria(['id' => 5]))->matches($row), "'5' = 5 like in SQL");
        $this->assertTrue((new Criteria(['status' => [1, 2], 'deleted_at' => null]))->matches($row));
        $this->assertTrue((new Criteria(['views' => ['>' => 40, '<=' => 42]]))->matches($row));
        $this->assertTrue((new Criteria(['title' => ['like' => 'php%']]))->matches($row));
        $this->assertTrue((new Criteria(['p.status' => 1]))->matches($row), 'alias prefix is ignored in memory');
        $this->assertTrue((new Criteria([Criteria::OR => [['id' => 1], ['views' => 42]]]))->matches($row));

        $this->assertFalse((new Criteria(['status' => ['!=' => 1]]))->matches($row));
        $this->assertFalse((new Criteria(['deleted_at' => ['!=' => null]]))->matches($row));
        $this->assertFalse((new Criteria(['missing' => 1]))->matches($row), 'NULL never equals a value');
        $this->assertFalse((new Criteria([Criteria::OR => [['id' => 1], ['views' => 1]]]))->matches($row));
    }

    public function testMentions(): void
    {
        $criteria = new Criteria(['a' => 1, Criteria::OR => [['p.deleted_at' => null]]]);
        $this->assertTrue($criteria->mentions('deleted_at'));
        $this->assertFalse($criteria->mentions('b'));
    }

    public function testUnknownOperatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Criteria(['id' => ['~' => 1]]);
    }
}
