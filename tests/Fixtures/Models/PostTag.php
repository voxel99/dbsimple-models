<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;

/** Pivot-таблица связи many-to-many Post <-> Tag */
class PostTag extends Model
{
    protected $table = 'post_tag';
    protected string $fields = 'id, post_id, tag_id';
    protected $types = ['int' => 'id, post_id, tag_id'];
}
