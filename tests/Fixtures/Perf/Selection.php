<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class Selection extends Model
{
    protected $table = 'perf_selections';
    protected string $fields = 'id, post_id, title, slug, cover_id, position, items_count, status, created_at';
    protected $types = ['int' => 'id, post_id, cover_id, position, items_count, status', 'datetime' => 'created_at'];

    public function __construct($data = null)
    {
        $this->belongs(Image::class, 'cover', 'cover_id', 'id');
        parent::__construct($data);
    }
}
