<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

/**
 * Пост, приближённый к прод-модели: ~60 полей, ~25 отношений (одно с pivot, одно полиморфное
 * через Attach), computed/explicit/extra/hidden/guarded, касты json/int/date/flags/bool/delim
 * и ~220 методов (PadMethods).
 */
class Post extends Model
{
    use PadMethods;

    protected $table = 'perf_posts';
    protected string $fields = 'id, user_id, editor_id, category_id, blog_id, type, status, title, subtitle, slug, lead,
        body, body_format, cover_id, preview_id, lang, flags, options, meta, keywords, source_url, source_title,
        views, likes, dislikes, rating, comments_count, bookmarks_count, shares_count, reading_time, words_count,
        is_ad, is_pinned, is_draft, is_locked, age_limit, region_id, city_id, geo_lat, geo_lng, layout, template,
        sort, score, hot_score, version, hash, ip, user_agent, internal_note, moderated_by, moderated_at,
        published_on, published_at, scheduled_at, edited_at, created_at, updated_at, deleted_at, extra_data';
    protected string $hidden = 'internal_note, ip, user_agent';
    protected string $guarded = 'views, hash';
    protected string $computed = 'excerpt, url, is_published, comments_label';
    protected string $explicit = 'share_url';
    protected string $extra = 'highlight';
    protected $types = [
        'int' => 'id, user_id, editor_id, category_id, blog_id, status, cover_id, preview_id, views, likes, dislikes,
            comments_count, bookmarks_count, shares_count, reading_time, words_count, age_limit, region_id, city_id,
            sort, version, moderated_by',
        'float' => 'rating, score, hot_score, geo_lat, geo_lng',
        'bool' => 'is_ad, is_pinned, is_draft, is_locked',
        'flags' => ['flags' => ['nsfw' => 0, 'comments_closed' => 1, 'featured' => 2, 'editorial' => 3, 'sponsored' => 4]],
        'json' => 'meta(seo_title, seo_description, og_*, marks: flags), options, extra_data',
        'delim' => 'keywords',
        'date' => 'published_on',
        'datetime' => 'published_at, scheduled_at, edited_at, moderated_at, created_at, updated_at, deleted_at',
    ];

    public function __construct($data = null)
    {
        $this->belongs(User::class, 'author', 'user_id', 'id');
        $this->belongs(User::class, 'editor', 'editor_id', 'id');
        $this->belongs(User::class, 'moderator', 'moderated_by', 'id');
        $this->belongs(Category::class, 'category', 'category_id', 'id');
        $this->belongs(Image::class, 'cover', 'cover_id', 'id');
        $this->hasOne(Preview::class, 'preview', 'id', 'post_id');
        $this->hasMany(Tag::class, 'tags', 'id', 'id', [], PostTag::class, 'post_id', 'tag_id');
        $this->hasMany(Selection::class, 'selections', 'id', 'post_id');
        $this->hasMany(Attach::class, 'attaches', 'id', 'post_id');
        $this->hasMany(Comment::class, 'comments', 'id', 'post_id');
        $this->hasMany(Comment::class, 'best_comments', 'id', 'post_id', ['rating > 10']);
        $this->hasOne(Counter::class, 'views_counter', 'id', 'post_id', ['kind' => 'views']);
        $this->hasOne(Counter::class, 'likes_counter', 'id', 'post_id', ['kind' => 'likes']);
        $this->hasOne(Counter::class, 'shares_counter', 'id', 'post_id', ['kind' => 'shares']);
        $this->hasMany(Counter::class, 'daily_counters', 'id', 'post_id');
        $this->hasMany(Image::class, 'gallery', 'id', 'user_id');
        $this->hasMany(Post::class, 'related', 'id', 'blog_id');
        $this->hasMany(Post::class, 'translations', 'id', 'blog_id');
        $this->belongs(Post::class, 'original', 'blog_id', 'id');
        $this->hasMany(User::class, 'coauthors', 'id', 'id');
        $this->hasMany(User::class, 'likers', 'id', 'id');
        $this->hasMany(Selection::class, 'featured_in', 'id', 'post_id');
        $this->hasMany(Attach::class, 'files', 'id', 'post_id');
        $this->hasOne(Preview::class, 'social_preview', 'id', 'post_id');
        $this->belongs(Category::class, 'region', 'region_id', 'id');
        parent::__construct($data);
    }

    public function excerpt(): string
    {
        return mb_substr(strip_tags((string) $this->lead), 0, 80);
    }

    public function url(): string
    {
        return '/p/' . $this->id . '-' . $this->slug;
    }

    public function is_published(): bool
    {
        return (int) $this->status === 1;
    }

    public function comments_label(): string
    {
        return $this->comments_count . ' comments';
    }

    public function share_url(): string
    {
        return 'https://example.com' . $this->url();
    }

    public function changeTitle($new, $old)
    {
        return trim((string) $new);
    }
}
