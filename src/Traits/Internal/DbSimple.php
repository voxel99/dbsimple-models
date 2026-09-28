<?php

namespace Jam\Models\Traits\Internal;

use Exception;
use Jam\DbSimple\DatabaseInterface;
use Jam\Models\Model;

trait DbSimple
{
    public static array $dbList = [];

    public static function initDbSimple(array $list)
    {
        self::$dbList = $list;
    }

    public static function getDbConnection(string $connection = Model::DB_MASTER)
    {
        if (!isset(self::$dbList[$connection])) {
            throw new Exception('Db not initialized, `' . $connection . '` not found in db list');
        }
        return self::$dbList[$connection];
    }

    /**
     * Соединение с БД
     * @var string
     */
    protected string $jamConnection = Model::DB_MASTER;

    /**
     * Объект БД, связанный с соединением
     * @var Database
     */
    protected $db;

    /**
     * Объект соединения модели (для escape(), транзакций и сырых запросов)
     * @return DatabaseInterface|\Jam\DbSimple\Connect
     */
    public function getDb(): object
    {
        return $this->db ?? self::getDbConnection($this->jamConnection);
    }

    /**
     * Соединение подхватывается при создании модели, если оно зарегистрировано.
     * Модели на не-SQL хранилище (см. Model::createStorage()) SQL-соединение не нужно,
     * поэтому его отсутствие — ошибка только при первом обращении к БД (getDb()).
     */
    public function bootDBSimple()
    {
        $this->db = self::$dbList[$this->jamConnection] ?? null;
    }

    public static function hasDbConnection(string $connection): bool
    {
        return isset(self::$dbList[$connection]);
    }

    /**
     * Назначить БД (мастер или один из слейвов)
     *
     * @param string $connection Строка соединения, см. глобальный конфиг, секцию 'connections'
     * @return $this
     * @throws Exception
     */
    public function db($connection)
    {
        // Проверяем именно запрошенное соединение (раньше проверялось текущее,
        // и db('unknown') молча записывал null в $this->db)
        $this->db = self::getDbConnection($connection);
        $this->jamConnection = $connection;
        return $this;
    }
}
