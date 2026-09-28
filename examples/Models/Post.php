<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

use Jam\Models\StringableInterface;
use Jam\Models\Traits\SoftDeletes;
use Jam\Models\Traits\Stringable;
use Jam\Models\Traits\Timestamps;
use Jam\Models\Utils\ArrayHelper;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property string $title
 * @property string|null $body
 * @property int $status          в БД — число, в API (toArray(':s')) — 'draft'/'published'
 * @property object $flags        битовая маска: {pinned, featured, comments_closed}
 * @property int $views
 */
class Post extends BaseModel implements StringableInterface
{
    use Timestamps;
    use SoftDeletes;
    use Stringable;

    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;

    protected $table = 'posts';
    protected string $fields = 'id, user_id, category_id, title, body, status, flags, views, created_at, updated_at, deleted_at';
    protected string $computed = 'excerpt';
    protected $types = [
        'int' => 'id, user_id, category_id, status, views',
        // Номер бита => имя флага. В БД — одно целое число.
        'flags' => ['flags' => ['pinned' => 0, 'featured' => 1, 'comments_closed' => 2]],
    ];

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'author', 'user_id', 'id');
        $this->belongs(Category::class, 'category', 'category_id', 'id');
        $this->hasMany(Comment::class, 'comments', 'id', 'post_id');
        // many-to-many: hasMany + pivot-модель и её ключи (post_tag.post_id -> posts.id, post_tag.tag_id -> tags.id)
        $this->hasMany(Tag::class, 'tags', 'id', 'id', [], PostTag::class, 'post_id', 'tag_id');
        parent::__construct($data);
    }

    public static function strings(): array
    {
        return ['status' => [self::STATUS_DRAFT => 'draft', self::STATUS_PUBLISHED => 'published']];
    }

    public function excerpt(): string
    {
        $body = (string) $this->body;
        return mb_strlen($body) > 40 ? mb_substr($body, 0, 40) . '…' : $body;
    }

    public static function published(): static
    {
        return static::instance()->where('status = ?d', self::STATUS_PUBLISHED);
    }

    /**
     * «With-профиль» поста: какие данные подгружать вместе с постом — в одном месте,
     * а КАКИЕ ИМЕННО — решает вызывающий код параметрами.
     *
     *     Post::instance()->with(Post::commonWithString(viewerId: $me, comments: true))->all();
     *     User::instance()->with(Post::commonWithString('posts:o(id)'))->all();   // вложенно
     *
     * @param string $name     путь, к которому «прикрепить» профиль ('' — от самого поста,
     *                         'posts:o(id)' — от связи posts родителя, 'subject' — полиморфная связь)
     * @param int    $viewerId кто смотрит: от этого зависят условия (свои неодобренные комментарии)
     * @param bool   $comments комментарии (только одобренные + свои)
     * @param bool   $commentsDeep авторы комментариев с профилями
     * @param bool   $full     всё остальное для страницы поста: категория с родителем, профиль автора
     */
    public static function commonWithString(
        string $name = '',
        int $viewerId = 0,
        bool $comments = false,
        bool $commentsDeep = false,
        bool $full = false,
    ): string {
        // Всегда: автор (только нужные колонки + computed) и теги в стабильном порядке.
        // ! — в toArray() будет [] вместо null: форма ответа API не зависит от данных.
        $with = [
            'author(id, name, email, role_id):c',
            'author.role(id, code)',
            'tags!:o(name)',
        ];

        if ($comments) {
            // Условие зависит от того, кто смотрит: гость видит одобренные,
            // пользователь — ещё и свои, ожидающие модерации.
            $commentsString = $viewerId
                ? sprintf('comments[is_approved = 1 OR user_id = %d]!:o(id)', $viewerId)
                : 'comments[is_approved = 1]!:o(id)';
            $with[] = $commentsString;
            if ($commentsDeep) {
                // Путь comments повторяется: узлы сливаются, условие и флаги задаются один раз выше
                $with[] = 'comments.author(id, name)';
                $with[] = 'comments.author.profile(id, user_id, bio)';
            }
        }

        if ($full) {
            $with[] = 'category(id, parent_id, title).parent(id, title)';
            $with[] = 'author.profile!';
        }

        return implode(', ', array_unique(ArrayHelper::extendsWithName($with, $name)));
    }
}
