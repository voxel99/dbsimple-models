<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;

/**
 * Полиморфная связь: subject — это Post или Comment в зависимости от subject_type.
 */
class Activity extends Model
{
    protected $table = 'activities';
    protected string $fields = 'id, user_id, subject_type, subject_id';
    protected $types = ['int' => 'id, user_id, subject_id'];

    public function __construct($data = null)
    {
        $this->belongs(
            $this->classCallback(['post' => Post::class, 'comment' => Comment::class], 'subject_type'),
            'subject',
            'subject_id',
            'id'
        );
        $this->belongs(User::class, 'user', 'user_id', 'id');
        parent::__construct($data);
    }
}
