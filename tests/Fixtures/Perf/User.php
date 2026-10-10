<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class User extends Model
{
    use PadMethods;

    protected $table = 'perf_users';
    protected string $fields = 'id, login, email, name, surname, password_hash, avatar_id, cover_id, status, role, karma, rating,
        followers, following, posts_count, flags, settings, city, country, about, site, birthday, gender, locale,
        last_seen_at, created_at, updated_at';
    protected string $hidden = 'password_hash, email';
    protected string $guarded = 'password_hash, karma';
    protected string $computed = 'display_name, profile_url';
    protected string $extra = 'is_online';
    protected $types = [
        'int' => 'id, avatar_id, cover_id, status, karma, followers, following, posts_count',
        'float' => 'rating',
        'flags' => ['flags' => ['verified' => 0, 'banned' => 1, 'pro' => 2, 'staff' => 3]],
        'json' => 'settings(theme, lang, notify: flags, x_*)',
        'date' => 'birthday',
        'datetime' => 'last_seen_at, created_at, updated_at',
    ];

    public function __construct($data = null)
    {
        $this->belongs(Image::class, 'avatar', 'avatar_id', 'id');
        $this->belongs(Image::class, 'cover', 'cover_id', 'id');
        $this->hasMany(Post::class, 'posts', 'id', 'user_id');
        $this->hasMany(Image::class, 'images', 'id', 'user_id');
        $this->hasOne(Counter::class, 'counter', 'id', 'user_id');
        parent::__construct($data);
    }

    public function display_name(): string
    {
        return trim($this->name . ' ' . $this->surname) ?: (string) $this->login;
    }

    public function profile_url(): string
    {
        return '/u/' . $this->login;
    }

    public function changeLogin($new, $old)
    {
        return strtolower(trim((string) $new));
    }
}
