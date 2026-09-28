<?php

/**
 * 10. Несколько соединений: master и реплики.
 *
 *   Model::initDbSimple([Model::DB_MASTER => $master, Model::DB_SLAVE => $replica, 'stats' => $statsDb]);
 *   User::instance()->db(Model::DB_SLAVE)->with('posts')->all();
 *
 * Выбранное соединение наследуют связи, загруженные через with(), и модели, созданные из строк.
 * Модель помнит соединение: save() прочитанной с реплики модели пойдёт на реплику.
 *
 * Best practice: реплика — только для чтения (списки, отчёты). Для изменения перечитайте запись
 * из master: данные реплики могут отставать, а save() пишет ВСЕ загруженные поля, т.е.
 * $replicaModel->db(Model::DB_MASTER)->save() перезапишет master устаревшими значениями.
 */

declare(strict_types=1);

use Jam\Models\Examples\Models\User;
use Jam\Models\Model;
use Jam\DbSimple\Adapter\Sqlite;

require __DIR__ . '/bootstrap.php';
$master = examples_connect(false);
examples_seed();

// «Реплика» — отдельная база с чуть отличающимися данными, чтобы было видно, откуда чтение
$replica = new Sqlite(['path' => ':memory:']);
$replica->getPdo()->exec(EXAMPLES_SCHEMA);
$replica->getPdo()->exec("INSERT INTO users (id, email, name) VALUES (1, 'alice@example.com', 'Alice (replica)')");
$replica->getPdo()->exec("INSERT INTO posts (id, user_id, title) VALUES (1, 1, 'Replica post')");
examples_log_sql($master, 'master');
examples_log_sql($replica, 'replica');

Model::initDbSimple([Model::DB_MASTER => $master, Model::DB_SLAVE => $replica]);

section('Чтение с реплики (вместе со связями)');
$user = User::instance()->db(Model::DB_SLAVE)->with('posts')->id(1)->first();
show('name', $user->name);
show('posts', $user->posts->column('title'));

section('Запись — перечитать из master и изменить');
$fresh = User::instance()->db(Model::DB_MASTER)->id(1)->first();
$fresh->name = 'Alice';
$fresh->update('name');

section('Типичный паттерн: репозиторий решает, откуда читать');
final class UserReadRepository
{
    public function __construct(private string $connection = Model::DB_SLAVE)
    {
    }

    public function listActive(): array
    {
        return User::active()->db($this->connection)->orderBy('id')->all()->toArray();
    }
}
show('active (replica)', array_column((new UserReadRepository())->listActive(), 'name'));
