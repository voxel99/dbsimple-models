<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

/**
 * Полиморфная связь: класс subject выбирается по значению subject_type в каждой строке.
 * classCallback() строит Closure: row -> class-string. Для каждого класса — отдельный запрос.
 */
class Activity extends BaseModel
{
    protected $table = 'activities';
    protected string $fields = 'id, user_id, action, subject_type, subject_id';
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
