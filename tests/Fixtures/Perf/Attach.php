<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class Attach extends Model
{
    protected $table = 'perf_attaches';
    protected string $fields = 'id, post_id, type, upload_id, position, caption, source, meta, created_at';
    protected $types = [
        'int' => 'id, post_id, upload_id, position',
        'json' => 'meta',
    ];

    public function __construct($data = null)
    {
        // Полиморфная связь: класс зависит от строки (для type=text класса нет)
        $this->belongs($this->classCallback([
            'image' => UploadImage::class,
            'video' => UploadVideo::class,
        ]), 'upload', 'upload_id', 'id');
        parent::__construct($data);
    }
}
