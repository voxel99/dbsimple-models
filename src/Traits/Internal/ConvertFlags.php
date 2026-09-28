<?php

namespace Jam\Models\Traits\Internal;

use Exception;
use Jam\Models\Utils\ArrayHelper;
use Jam\Models\Utils\Strings;

trait ConvertFlags
{
    public function parseRelationAlias($partsStr)
    {
        $exploded = Strings::reSplitOutBraces($partsStr, ":");
        // Обрабатываем левую часть с алиасом и, возможно, скобками
        $fields = $where = $exclude = [];
        $alias = "";
        $required = true;
        $retEmpty = false;

        if (
            preg_match("{(\w+)
                # Wheres
                (\[[^\]]+\])?
                # Fields
                (\([^\)]+\))?
                # Exclude
                (\^[^\^]+\^)?
                #Not required
                (\?)?
                #Return empty object if null
                (!)?
            }x", $exploded[0], $m) && !empty($m[1])
        ) {
            $alias = $m[1];
            // Wheres
            if (!empty($m[2])) {
                $where = trim($m[2], "[] ");
            }
            // Fields
            if (!empty($m[3])) {
                $fields = ArrayHelper::stringCommasToArray(trim($m[3], "() "));
            }
            // Exclude
            if (!empty($m[4])) {
                $exclude = ArrayHelper::stringCommasToArray(trim($m[4], "^ "));
            }
            // Not required
            if (!empty($m[5])) {
                $required = false;
            }
            // With empty
            if (!empty($m[6])) {
                $retEmpty = true;
            }
        }
        return [$alias, $fields, $where, $exclude, $required, $retEmpty];
    }


    public function parseFlags($partsStr)
    {
        $flags = [];
        $flagsNoArgs = $this->flagsNoArgs();
        $flagsWithArgs = $this->flagsWithArgs();

        $exploded = Strings::reSplitOutBraces($partsStr, ":");
        if (!empty($exploded)) {
            if ($partsStr[0] !== ':') {
                array_shift($exploded);
            }
            $flagsStr = implode("", $exploded);
            $debugPos = 1; // 1 символ = двоеточие
            if (preg_match_all("#:?(\w)(\([^\)]+\))?#", $flagsStr, $m) && !empty($m[1])) {
                foreach ($m[1] as $idx => $flagLetter) {
                    $realStr = $m[0][$idx];
                    $flagArgs = trim(str_replace("|", ",", $m[2][$idx]), "()");
                    $isFlagNoArgs = in_array($flagLetter, $flagsNoArgs);
                    $isFlagWithArgs = in_array($flagLetter, $flagsWithArgs);
                    if (!$isFlagNoArgs && !$isFlagWithArgs) {
                        throw new Exception(sprintf("Flag not found [%s] in flags expression [:%s], pos %d", $flagLetter, $flagsStr, $debugPos));
                    }
                    if ($isFlagNoArgs && !$isFlagWithArgs && $flagArgs !== "") {
                        throw new Exception(sprintf("Flag must not have arguments [%s] in flags expression [:%s], pos %d", $flagLetter, $flagsStr, $debugPos));
                    }
                    if ($isFlagWithArgs && !$isFlagNoArgs && $flagArgs === "") {
                        throw new Exception(sprintf("Flag must have arguments [%s] in flags expression [:%s], pos %d", $flagLetter, $flagsStr, $debugPos));
                    }
                    $message = "";
                    if (!$this->flagCheckArg($flagLetter, $flagArgs, $message)) {
                        throw new Exception(sprintf("Flag [%s] have argument error [%s] in flags expression [:%s], pos %d", $flagLetter, $message, $flagsStr, $debugPos));
                    }

                    $flags[$flagLetter] = $isFlagNoArgs && $flagArgs === "" ? true : $flagArgs;
                    $debugPos += mb_strlen($realStr);
                }
            }
        }
        return $flags;
    }

    public function flagsWithArgs()
    {
        return [
            self::FLAG_STRINGABLE,
            self::FLAG_HIDDEN,
            self::FLAG_ORDER,
            self::FLAG_LIMIT,
            self::FLAG_OFFSET,
            self::FLAG_PIVOT,
            self::FLAG_KEYBY,
            self::FLAG_TITLES
        ];
    }

    public function flagsNoArgs()
    {
        return [
            self::FLAG_COMPUTABLE,
            self::FLAG_UPDATABLE,
            self::FLAG_HIDDEN,
            self::FLAG_DEPENDENCIES,
            self::FLAG_EMPTY,
            self::FLAG_STRINGABLE,
            self::FLAG_SCALAR,
            self::FLAG_EXTRA,
            self::FLAG_PIVOT,
            self::FLAG_TITLES,
            self::FLAG_IDENTIFIERS,
            self::FLAG_WITH_TRASHED,
            self::FLAG_INTERNAL_DATABASE
        ];
    }

    public function flagCheckArg($flag, $arg, &$message = "")
    {
        $stat = true;
        switch ($flag) {
            case self::FLAG_KEYBY:
            case self::FLAG_ORDER:
                $message = "Param must not be empty";
                $stat = !empty($arg); // Строка id DESC, status ASC
                break;

            case self::FLAG_LIMIT:
            case self::FLAG_OFFSET:
                $message = "Param must be integer";
                $stat = is_numeric($arg);
                break;
        }
        return $stat;
    }

    public function getFlag($flags, $letter)
    {
        return $flags[$letter] ?? false ;
    }
}
