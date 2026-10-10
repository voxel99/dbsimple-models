<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class Comment extends Model
{
    protected $table = 'perf_comments';
    protected string $fields = 'id, post_id, user_id, parent_id, body, rating, status, created_at';
    protected $types = ['int' => 'id, post_id, user_id, parent_id, rating, status'];

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'author', 'user_id', 'id');
        parent::__construct($data);
    }
}
