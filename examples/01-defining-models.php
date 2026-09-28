<?php

/**
 * 01. Описание модели: поля, скрытые/защищённые/вычислимые/extra-поля, мутаторы.
 *
 * Модель — это класс с набором строковых свойств-списков:
 *   $table     — имя таблицы (по умолчанию snake_case от имени класса: BlogPost -> blog_post)
 *   $pk        — первичный ключ (по умолчанию 'id')
 *   $fields    — ВСЕ колонки таблицы (обязательно полный список, см. ниже)
 *   $hidden    — не отдаются в toArray() без флага :h
 *   $guarded   — нельзя присвоить снаружи
 *   $computed  — вычисляются методом с тем же именем
 *   $extra     — временные поля: живут в модели, в БД не попадают
 *   $types     — приведение типов (см. 04-casting.php)
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\User;
use Jam\Models\ModelException;

require __DIR__ . '/bootstrap.php';
examples_connect();

section('Создание модели из массива');
$user = new User([
    'email' => '  Alice@Example.COM ',       // мутатор changeEmail() нормализует значение
    'name' => 'Alice',
    'interests' => ['php', 'sql'],
    'plain_password' => 'from-signup-form',   // extra-поле
]);
show('email', $user->email);
show('display_name (computed)', $user->display_name);
show('plain_password (extra)', $user->plain_password);

section('Неизвестное поле — исключение, а не молчаливая потеря данных');
try {
    new User(['emial' => 'typo@example.com']);
} catch (ModelException $e) {
    show('ModelException', strtok($e->getMessage(), "\n"));
}
// Если источник данных «грязный» (например, внешний API), используйте тихий режим —
// неподходящие поля пропускаются, а сообщение пишется в error_log:
$lenient = (new User())->fromArray(['email' => 'x@example.com', 'unknown' => 1], true);
show('fromArray(..., silence: true)', $lenient->toArray());

section('Guarded-поле');
try {
    $user->password_hash = 'raw-hash';
} catch (ModelException $e) {
    show('ModelException', $e->getMessage());
}
$user->setPassword('s3cret');  // внутри модели: $this->fromArray(['password_hash' => ...])
show('checkPassword(s3cret)', $user->checkPassword('s3cret'));

section('Что попадает в toArray()');
show('toArray()', $user->toArray());
show("toArray(':chx') — + computed, hidden, extra", array_keys($user->toArray(':chx')));

section('Вычислимое поле пересчитывается после изменения исходных');
$user->name = 'Alice Cooper';
show('display_name', $user->display_name);

section('Присваивание вычислимому полю игнорируется');
$user->display_name = 'hacked';
show('display_name', $user->display_name);

section('isset / unset / isEmpty');
show('isset($user->name)', isset($user->name));
unset($user->name);
show('после unset: $user->name', $user->name);
show('(new User())->isEmpty()', (new User())->isEmpty());

section('Правило: $fields — полный список колонок');
echo <<<TXT
  Выборки выполняются как SELECT * (или с явным списком полей). Если в таблице появилась колонка,
  которой нет в \$fields, чтение упадёт с ModelException "[col] is not a correct field".
  Поэтому миграция, добавляющая колонку, должна сопровождаться правкой \$fields.

TXT;
