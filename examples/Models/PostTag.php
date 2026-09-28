<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

/**
 * Pivot-модель many-to-many. Нужна собственная колонка id: после вставки ORM
 * присваивает модели первичный ключ, а поле должно существовать в $fields.
 */
class PostTag extends BaseModel
{
    protected $table = 'post_tag';
    protected string $fields = 'id, post_id, tag_id';
    protected $types = ['int' => 'id, post_id, tag_id'];
}
