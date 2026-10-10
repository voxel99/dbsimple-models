<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class Thumb extends Model
{
    protected $table = 'perf_thumbs';
    protected string $fields = 'id, image_id, kind, width, height, url, format, size, retina';
    protected $types = [
        'int' => 'id, image_id, width, height, size',
        'bool' => 'retina',
    ];
}
