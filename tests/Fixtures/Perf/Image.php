<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class Image extends Model
{
    protected $table = 'perf_images';
    protected string $fields = 'id, user_id, path, width, height, mime, size, meta, focus, created_at';
    protected string $computed = 'ratio';
    protected $types = [
        'int' => 'id, user_id, width, height, size',
        'json' => 'meta',
        'datetime' => 'created_at',
    ];

    public function __construct($data = null)
    {
        $this->hasMany(Thumb::class, 'thumbs', 'id', 'image_id');
        $this->belongs(User::class, 'owner', 'user_id', 'id');
        parent::__construct($data);
    }

    public function ratio(): ?float
    {
        return $this->height ? round($this->width / $this->height, 3) : null;
    }
}
