<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\IntableInterface;
use Jam\Models\Model;
use Jam\Models\StringableInterface;
use Jam\Models\Traits\Intable;
use Jam\Models\Traits\Stringable;

/**
 * Stringable: в БД хранится число, наружу (API) отдаётся строковый код — toArray(':s').
 * Intable: обратное отображение для кодов, которые удобнее хранить числом.
 */
class Article extends Model implements StringableInterface, IntableInterface
{
    use Stringable;
    use Intable;

    public const STATUS_BANNED = -1;
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;

    protected $table = 'posts';
    protected string $fields = 'id, title, status';
    protected $types = ['int' => 'id, status'];

    public static function strings(): array
    {
        return [
            'status' => [
                self::STATUS_BANNED => 'banned', // отрицательный ключ — «служебное» значение
                self::STATUS_DRAFT => 'draft',
                self::STATUS_PUBLISHED => 'published',
            ],
        ];
    }

    public static function ints(): array
    {
        return ['priority' => ['low' => 10, 'normal' => 20, 'high' => 30]];
    }

    /** Описания значений для stringsDescription('status'): метод по имени поля + 'es' */
    public static function statuses(): array
    {
        return [self::STATUS_BANNED => 'Banned by moderator', self::STATUS_DRAFT => 'Draft', self::STATUS_PUBLISHED => 'Published'];
    }
}
