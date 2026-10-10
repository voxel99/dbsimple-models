<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Tests\Support\ToArrayGolden;
use PHPUnit\Framework\TestCase;

/**
 * Вывод toArray() совпадает с 1dc70de: гидрация на прод-подобных фикстурах, pivot-отношения
 * (@pivot — только по флагу p), явно присвоенный пустой hasMany ([]), пустые результаты with()
 * (ключа нет).
 *
 * Снимок снят на исходниках 1dc70de (см. tests/Support/make_golden_1dc70de.php) и
 * перегенерируется только оттуда — не из текущего кода.
 */
final class ToArrayGolden1dc70deTest extends TestCase
{
    public function testToArrayMatches1dc70de(): void
    {
        // Сравнивается JSON, как его отдаёт API: объекты не схлопываются в массивы ({} ≠ [])
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $expected = (array) json_decode((string) file_get_contents(ToArrayGolden::FILE), false, 512, JSON_THROW_ON_ERROR);
        $actual = ToArrayGolden::snapshot();

        $this->assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $key => $value) {
            $this->assertSame(json_encode($value, $flags), json_encode($actual[$key], $flags), $key);
        }
    }
}
