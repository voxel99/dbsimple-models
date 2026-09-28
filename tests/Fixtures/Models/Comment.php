<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;

class Comment extends Model
{
    protected $table = 'comments';
    protected string $fields = 'id, post_id, user_id, body, is_approved';
    protected $types = [
        'int' => 'id, post_id, user_id',
        'bool' => 'is_approved',
    ];

    public function __construct($data = null)
    {
        $this->belongs(Post::class, 'post', 'post_id', 'id');
        $this->belongs(User::class, 'author', 'user_id', 'id');
        parent::__construct($data);
    }
}
