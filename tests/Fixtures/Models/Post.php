<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Traits\SoftDeletes;
use Jam\Models\Traits\Timestamps;

class Post extends Model
{
    use Timestamps;
    use SoftDeletes;

    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;

    protected $table = 'posts';
    protected string $fields = 'id, user_id, category_id, title, body, status, flags, views, created_at, updated_at, deleted_at';
    protected string $computed = 'excerpt';
    protected $types = [
        'int' => 'id, user_id, category_id, status, views',
        'flags' => ['flags' => ['pinned' => 0, 'featured' => 1, 'comments_closed' => 2]],
    ];

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'author', 'user_id', 'id');
        $this->belongs(Category::class, 'category', 'category_id', 'id');
        $this->hasMany(Comment::class, 'comments', 'id', 'post_id');
        $this->hasMany(Tag::class, 'tags', 'id', 'id', [], PostTag::class, 'post_id', 'tag_id');
        parent::__construct($data);
    }

    public function excerpt(): string
    {
        return mb_substr((string) $this->body, 0, 20);
    }
}
