<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Traits\Slugable;

class Category extends Model
{
    use Slugable;

    protected $table = 'categories';
    protected string $fields = 'id, parent_id, title, slug';
    protected $types = ['int' => 'id, parent_id'];

    public function __construct($data = null)
    {
        $this->belongs(Category::class, 'parent', 'parent_id', 'id');
        $this->hasMany(Category::class, 'children', 'id', 'parent_id');
        $this->hasMany(Post::class, 'posts', 'id', 'category_id');
        parent::__construct($data);
    }
}
