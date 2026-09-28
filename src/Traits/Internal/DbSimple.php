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

    public function bootDBSimple()
    {
        $this->db = self::getDbConnection($this->jamConnection);
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
