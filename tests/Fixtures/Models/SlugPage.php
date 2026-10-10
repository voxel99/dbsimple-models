<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Traits\SlugEvaluatable;

class SlugPage extends Model
{
    use SlugEvaluatable;

    protected $table = 'pages';
    protected string $fields = 'id, title, slug, slug_index, slug_hash';
    protected $types = ['int' => 'id, slug_index'];
}
