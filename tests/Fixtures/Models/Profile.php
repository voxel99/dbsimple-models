<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;

class Profile extends Model
{
    protected $table = 'profiles';
    protected string $fields = 'id, user_id, bio, website';

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'user', 'user_id', 'id');
        parent::__construct($data);
    }
}
