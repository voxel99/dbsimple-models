<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class Preview extends Model
{
    protected $table = 'perf_previews';
    protected string $fields = 'id, post_id, image_id, title, text, crop, created_at';
    protected $types = ['int' => 'id, post_id, image_id', 'json' => 'crop'];

    public function __construct($data = null)
    {
        $this->belongs(Image::class, 'image', 'image_id', 'id');
        parent::__construct($data);
    }
}
