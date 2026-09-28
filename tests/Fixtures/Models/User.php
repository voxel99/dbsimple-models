<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Model;
use Jam\Models\Traits\Timestamps;

/**
 * @property int $id
 * @property string $email
 * @property string $name
 * @property string $password_hash
 * @property bool $is_active
 * @property array $roles
 * @property object|null $settings
 * @property string $created_at
 * @property string $updated_at
 * @property Profile $profile
 * @property \Jam\Models\ModelList<Post> $posts
 */
class User extends Model
{
    use Timestamps;

    protected $table = 'users';
    protected string $fields = 'id, email, name, password_hash, is_active, roles, settings, created_at, updated_at';
    protected string $hidden = 'password_hash';
    protected string $guarded = 'password_hash';
    protected string $computed = 'display_name';
    protected string $extra = 'plain_password';
    protected $types = [
        'int' => 'id',
        'bool' => 'is_active',
        'delim' => 'roles',
        'json' => 'settings',
    ];

    public function __construct($data = null)
    {
        $this->hasOne(Profile::class, 'profile', 'id', 'user_id');
        $this->hasMany(Post::class, 'posts', 'id', 'user_id');
        parent::__construct($data);
    }

    public function display_name(): string
    {
        return $this->name ? $this->name . ' <' . $this->email . '>' : (string) $this->email;
    }

    /**
     * Guarded-поле нельзя присвоить снаружи ($user->password_hash = ... бросит исключение),
     * но fromArray() (гидрация) пишет его штатно — этим и пользуемся внутри модели.
     */
    public function setPassword(string $password): static
    {
        $this->fromArray(['password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4])]);
        return $this;
    }

    public function checkPassword(string $password): bool
    {
        return password_verify($password, (string) $this->password_hash);
    }

    /** Мутатор change{Field}: вызывается при каждом присваивании $user->email = ... */
    public function changeEmail($new, $old)
    {
        return mb_strtolower(trim((string) $new));
    }
}
