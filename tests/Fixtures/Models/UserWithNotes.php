<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

/** SQL-модель User со связью на модель из другого хранилища (Note). */
class UserWithNotes extends User
{
    public function __construct($data = null)
    {
        $this->hasMany(Note::class, 'notes', 'id', 'user_id');
        parent::__construct($data);
    }
}
