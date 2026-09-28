<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

class Profile extends BaseModel
{
    protected $table = 'profiles';
    protected string $fields = 'id, user_id, bio, website';
    protected $types = ['int' => 'id, user_id'];

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'user', 'user_id', 'id');
        parent::__construct($data);
    }
}
