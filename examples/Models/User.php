<?php

declare(strict_types=1);

namespace Jam\Models\Examples\Models;

use Jam\Models\ModelList;
use Jam\Models\Traits\Timestamps;
use Jam\Models\Utils\ArrayHelper;

/**
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property string|null $password_hash
 * @property bool $is_active
 * @property int $role_id
 * @property array<int, string> $interests
 * @property object|null $settings
 * @property string|null $theme       top-level ключ JSON-колонки settings
 * @property string $created_at
 * @property string|null $updated_at
 * @property-read string $display_name
 * @property Profile|null $profile
 * @property ModelList<Post>|null $posts
 * @property Role|null $role
 */
class User extends BaseModel
{
    use Timestamps;

    protected $table = 'users';

    // ВСЕ колонки таблицы: выборки идут через SELECT *, и неизвестная колонка — это исключение.
    protected string $fields = 'id, email, name, password_hash, is_active, role_id, interests, settings, created_at, updated_at';

    // hidden: не попадает в toArray() без флага :h (но пишется в БД)
    protected string $hidden = 'password_hash';

    // guarded: нельзя присвоить снаружи ($user->password_hash = ...), только гидрацией/fromArray
    protected string $guarded = 'password_hash';

    // computed: вычисляются методом с тем же именем, в toArray() — с флагом :c
    protected string $computed = 'display_name';

    // extra: временные данные рядом с моделью, в БД не пишутся, в toArray() — с флагом :x
    protected string $extra = 'plain_password';

    protected $types = [
        'int' => 'id, role_id',
        'bool' => 'is_active',
        'delim' => 'interests',             // 'php,sql' <-> ['php', 'sql']
        'json' => 'settings(theme, lang)',  // theme и lang доступны как $user->theme
    ];

    public function __construct($data = null)
    {
        // Связи объявляются ДО parent::__construct(): конструктор разбирает $data,
        // и вложенные массивы (profile, posts) превращаются в модели по этим объявлениям.
        $this->hasOne(Profile::class, 'profile', 'id', 'user_id');
        $this->hasMany(Post::class, 'posts', 'id', 'user_id');
        $this->belongs(Role::class, 'role', 'role_id', 'id');
        parent::__construct($data);
    }

    public function display_name(): string
    {
        return $this->name ? sprintf('%s <%s>', $this->name, $this->email) : (string) $this->email;
    }

    /** Мутатор: вызывается при каждом присваивании $user->email (в т.ч. при чтении из БД). */
    public function changeEmail($new, $old)
    {
        return mb_strtolower(trim((string) $new));
    }

    /** Единственный легальный способ записать guarded-поле — изнутри модели через fromArray(). */
    public function setPassword(string $password): static
    {
        $this->fromArray(['password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4])]);
        return $this;
    }

    public function checkPassword(string $password): bool
    {
        return password_verify($password, (string) $this->password_hash);
    }

    /**
     * With-профиль пользователя. Посты подключаются через профиль поста —
     * их набор связей описан в одном месте (Post::commonWithString) и переиспользуется.
     */
    public static function commonWithString(string $name = '', int $viewerId = 0, bool $posts = false): string
    {
        $with = ArrayHelper::extendsWithName(['profile!', 'role(id, code, title)'], $name);
        if ($posts) {
            // Только опубликованные, свежие сверху; всё, что внутри поста, — из профиля поста.
            // Вложенному профилю передаём ПОЛНЫЙ путь: extendsWithName() к уже склеенной
            // строке применять нельзя — префикс получил бы только первый её элемент.
            [$postsPath] = ArrayHelper::extendsWithName(['posts[status = 1]:o(id DESC)'], $name);
            $with[] = Post::commonWithString($postsPath, $viewerId);
        }
        return implode(', ', array_unique($with));
    }

    /** «Скоуп» — обычный метод, возвращающий построитель с условием. */
    public static function active(): static
    {
        return static::instance()->where('is_active = ?d', 1);
    }
}
