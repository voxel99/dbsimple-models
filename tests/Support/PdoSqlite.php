<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Support;

use Jam\DbSimple\AdapterInterface;
use Jam\DbSimple\Database;
use Jam\DbSimple\DatabaseInterface;
use PDO;
use PDOException;

/**
 * Минимальный адаптер DbSimple поверх PDO SQLite.
 *
 * В jam/dbsimple штатный адаптер Sqlite рассчитан на расширение sqlite2 (sqlite_factory),
 * которого нет в современных сборках PHP. Этот адаптер повторяет логику Mypdo и позволяет
 * гонять тесты и примеры на in-memory базе без внешнего сервера.
 *
 * MySQL-диалект, который генерирует ORM, частично переводится в SQLite (см. prepareQuery).
 */
class PdoSqlite extends Database implements AdapterInterface, DatabaseInterface
{
    private PDO $link;

    /** Заполняется базовым Database::_query() (в jam/dbsimple свойство объявлено только в адаптерах) */
    public $attributes;

    /**
     * @param array{path?: string}|string $dsn Путь к файлу БД или ':memory:'
     */
    public function __construct(array|string $dsn = ':memory:')
    {
        $path = is_array($dsn) ? ($dsn['path'] ?? ':memory:') : $dsn;
        $this->link = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->link;
    }

    public function prepareQuery($query)
    {
        // INSERT IGNORE (MySQL) -> INSERT OR IGNORE (SQLite)
        $query[0] = preg_replace('/^\s*INSERT\s+IGNORE\s+/i', 'INSERT OR IGNORE ', $query[0]);
        return $query;
    }

    protected function _performGetPlaceholderIgnoreRe()
    {
        return '
            "   (?> [^"\\\\]+|\\\\"|\\\\)*    "   |
            \'  (?> [^\'\\\\]+|\\\\\'|\\\\)* \'   |
            `   (?> [^`]+ | ``)*              `   |   # backticks
            /\* .*?                          \*/      # comments
        ';
    }

    protected function _performEscape($s, $isIdent = false)
    {
        if (!$isIdent) {
            return $this->link->quote((string) $s);
        }
        return '`' . str_replace('`', '``', (string) $s) . '`';
    }

    protected function _performTransaction($parameters = null)
    {
        return $this->link->beginTransaction();
    }

    protected function _performCommit()
    {
        return $this->link->commit();
    }

    protected function _performRollback()
    {
        return $this->link->rollBack();
    }

    protected function _performQuery($queryMain)
    {
        $this->_expandPlaceholders($queryMain, false);
        try {
            $p = $this->link->query($queryMain[0]);
        } catch (PDOException $e) {
            return $this->_setLastError($e->getCode(), $e->getMessage(), $queryMain[0]);
        }
        if (!$p) {
            $info = $this->link->errorInfo();
            return $this->_setLastError($info[1], $info[2], $queryMain[0]);
        }
        if (preg_match('/^\s* INSERT \s+/six', $queryMain[0])) {
            return $this->link->lastInsertId();
        }
        if ($p->columnCount() == 0) {
            return $p->rowCount();
        }
        $res = $p->fetchAll(PDO::FETCH_ASSOC);
        $p->closeCursor();
        return $res;
    }

    protected function _performTransformQuery(&$queryMain, $how)
    {
        switch ($how) {
            case 'CALC_TOTAL':
                return true;
            case 'GET_TOTAL':
                $m = null;
                $re = '/^(\s* SELECT \s+)(.*?)(\s+ FROM \s+ .*?)((?:\s+ ORDER \s+ BY \s+ .*?)?)((?:\s+ LIMIT \s+ \S+ \s* (?: , \s* \S+ \s*)? )?)$/six';
                if (preg_match($re, $queryMain[0], $m)) {
                    $queryMain[0] = $m[1] . $this->_fieldList2Count($m[2]) . ' AS C' . $m[3];
                    $skipTail = substr_count($m[4] . $m[5], '?');
                    if ($skipTail) {
                        array_splice($queryMain, -$skipTail);
                    }
                }
                return true;
        }
        return false;
    }

    protected function _performNewBlob($id = null)
    {
        return null;
    }

    protected function _performGetBlobFieldNames($result)
    {
        return [];
    }

    protected function _performFetch($result)
    {
        return $result;
    }
}
