<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class UploadVideo extends Upload
{
    protected string $fields = 'id, user_id, type, path, name, mime, size, width, height, duration, data, hash, status, poster, created_at';
    protected $types = [
        'int' => 'id, user_id, size, width, height, duration, status',
        'json' => 'data, poster',
        'datetime' => 'created_at',
    ];

    public function __construct($data = null)
    {
        $this->belongs(Image::class, 'poster_image', 'id', 'id');
        parent::__construct($data);
    }
}
