<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class PostTag extends Model
{
    protected $table = 'perf_post_tag';
    protected string $fields = 'id, post_id, tag_id, position, is_main';
    protected $types = ['int' => 'id, post_id, tag_id, position', 'bool' => 'is_main'];
}
