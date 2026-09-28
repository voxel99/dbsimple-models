<?php

/**
 * 04. Приведение типов ($types).
 *
 * У каждого поля два слоя:
 *   $model->field       — внешнее значение (для PHP-кода): bool, array, object, float…
 *   $model->{'field:db'} — значение для БД (строка/число), именно оно уходит в INSERT/UPDATE.
 * Слой определяется автоматически по присваиваемому значению, конвертация ленивая.
 *
 * Встроенные типы:
 *   int, uint, float, string, date, datetime, null — скалярные (только при чтении)
 *   bool, delim, json, flags, ip                   — двусторонние
 *   closure => [field => MyCast::class]            — свой класс-наследник CastClosure
 */

declare(strict_types=1);

use Jam\Models\Cast\CastClosure;
use Jam\Models\Examples\Models\Post;
use Jam\Models\Examples\Models\User;
use Jam\Models\Model;

require __DIR__ . '/bootstrap.php';
examples_connect();

/** Свой cast: в БД копейки (int), в коде — рубли (float). */
final class MoneyCast extends CastClosure
{
    public function dbValue($value)
    {
        return (int) round(((float) $value) * 100);
    }

    public function externalValue($value)
    {
        return ((int) $value) / 100;
    }

    // Как понять, в каком слое пришло значение: int — из БД, float — из кода
    public function checkValue($value)
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? self::CAST_DB : self::CAST_EXTERNAL;
    }
}

final class Product extends Model
{
    protected string $fields = 'id, price, weight, released_on, updated, ip, parent_id, sku';
    protected $types = [
        'int' => 'id',
        'float' => 'weight',
        'date' => 'released_on',
        'datetime' => 'updated',
        'ip' => 'ip',                               // хранится бинарно (inet_pton), наружу — строка
        'null' => 'parent_id',                      // пустые значения ('0', '') -> null
        'string' => 'sku',
        'closure' => ['price' => MoneyCast::class],
    ];
}

section('Скалярные касты работают при чтении (данные из БД приходят строками)');
$p = new Product([
    'id' => '7', 'weight' => '1.25', 'released_on' => '2024-05-01 13:00:00', 'updated' => '2024-05-01 13:00:00',
    'ip' => inet_pton('192.168.0.10'), 'parent_id' => '0', 'sku' => 12345, 'price' => 199900,
]);
show('id', $p->id);
show('weight', $p->weight);
show('released_on', $p->released_on);
show('ip', $p->ip);
show('parent_id', $p->parent_id);
show('sku', $p->sku);
show('price', $p->price);

section('Двусторонние касты: присваиваем «внешнее» значение, в БД уходит «db»');
$p->price = 12.5;
$p->ip = '::1';
show("price:db", $p->{'price:db'});
show("ip:db (hex)", bin2hex($p->{'ip:db'}));

section('bool, delim, json с top-level ключами');
$user = new User(['email' => 'a@example.com', 'is_active' => true, 'interests' => ['php', 'sql']]);
$user->theme = 'dark';         // top-level ключ JSON-колонки settings
$user->lang = 'ru';
show('settings (объект)', $user->settings);
show('settings:db', $user->{'settings:db'});
show('interests:db', $user->{'interests:db'});
show('is_active:db', $user->{'is_active:db'});
$id = $user->save();
$loaded = User::instance()->id($id)->first();
show('после чтения из БД: theme', $loaded->theme ?? $loaded->settings->theme);

section('flags: несколько булевых (и небольших целых) значений в одном INT');
$post = new Post(['title' => 'Flags demo', 'flags' => ['pinned' => 1, 'featured' => 'yes']]);
show('flags (до сохранения — как присвоили)', $post->flags);
show('flags:db', $post->{'flags:db'});
show("toArray()['flags'] (наружу — yes/no)", $post->toArray()['flags']);
$post->flags = ['comments_closed' => 1];   // присваивание заменяет маску целиком
show('после замены', $post->{'flags:db'});

section("toArray(':_') — db-представление всех полей (так модель уходит в INSERT)");
show('user', $user->toArray(':_'));

section('NULL_VALUE');
$p->parent_id = Model::NULL_VALUE;
show('parent_id', $p->parent_id);
show('parent_id:db', $p->{'parent_id:db'});
