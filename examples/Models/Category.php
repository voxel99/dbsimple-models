<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

use Jam\Models\Traits\Slugable;

/** Дерево категорий: связь модели самой с собой. Slugable — поиск по id или slug. */
class Category extends BaseModel
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
