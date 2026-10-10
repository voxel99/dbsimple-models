<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class Tag extends Model
{
    protected $table = 'perf_tags';
    protected string $fields = 'id, name, slug, title, description, posts_count, color, flags';
    protected string $computed = 'url';
    protected $types = [
        'int' => 'id, posts_count',
        'flags' => ['flags' => ['hidden' => 0, 'featured' => 1]],
    ];

    public function __construct($data = null)
    {
        $this->belongs(Image::class, 'icon', 'id', 'id');
        parent::__construct($data);
    }

    public function url(): string
    {
        return '/tag/' . $this->slug;
    }
}
