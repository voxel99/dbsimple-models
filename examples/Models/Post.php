<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

use Jam\Models\StringableInterface;
use Jam\Models\Traits\SoftDeletes;
use Jam\Models\Traits\Stringable;
use Jam\Models\Traits\Timestamps;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property string $title
 * @property string|null $body
 * @property int $status          в БД — число, в API (toArray(':s')) — 'draft'/'published'
 * @property object $flags        битовая маска: {pinned, featured, comments_closed}
 * @property int $views
 */
class Post extends BaseModel implements StringableInterface
{
    use Timestamps;
    use SoftDeletes;
    use Stringable;

    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;

    protected $table = 'posts';
    protected string $fields = 'id, user_id, category_id, title, body, status, flags, views, created_at, updated_at, deleted_at';
    protected string $computed = 'excerpt';
    protected $types = [
        'int' => 'id, user_id, category_id, status, views',
        // Номер бита => имя флага. В БД — одно целое число.
        'flags' => ['flags' => ['pinned' => 0, 'featured' => 1, 'comments_closed' => 2]],
    ];

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'author', 'user_id', 'id');
        $this->belongs(Category::class, 'category', 'category_id', 'id');
        $this->hasMany(Comment::class, 'comments', 'id', 'post_id');
        // many-to-many: hasMany + pivot-модель и её ключи (post_tag.post_id -> posts.id, post_tag.tag_id -> tags.id)
        $this->hasMany(Tag::class, 'tags', 'id', 'id', [], PostTag::class, 'post_id', 'tag_id');
        parent::__construct($data);
    }

    public static function strings(): array
    {
        return ['status' => [self::STATUS_DRAFT => 'draft', self::STATUS_PUBLISHED => 'published']];
    }

    public function excerpt(): string
    {
        $body = (string) $this->body;
        return mb_strlen($body) > 40 ? mb_substr($body, 0, 40) . '…' : $body;
    }

    public static function published(): static
    {
        return static::instance()->where('status = ?d', self::STATUS_PUBLISHED);
    }
}
