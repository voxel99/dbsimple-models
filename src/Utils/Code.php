<?php

/**
 * Created by PhpStorm.
 * User: Voxel
 * Date: 05.08.2017
 * Time: 18:27
 */

namespace Jam\Models\Utils;

class Code
{
    /**
     * Returns all traits used by a class, it's subclasses and trait of their traits
     *
     * @param string $class
     * @return array<string, string> Trait names indexed by trait name
     */
    public static function classUsesRecursive($class)
    {
        $results = [];
        foreach (array_merge([$class => $class], class_parents($class)) as $class) {
            $results += self::traitUsesRecursive($class);
        }
        return array_unique($results);
    }

    public static function isUses($class, $traitName)
    {
        $uses = \class_uses($class);
        return in_array($traitName, $uses);
    }

    /**
     * Returns all traits used by a trait and its traits
     *
     * @param string $trait
     * @return array<string, string> Trait names indexed by trait name
     */
    public static function traitUsesRecursive($trait)
    {
        $traits = class_uses($trait);
        foreach ($traits as $trait) {
            $traits += self::traitUsesRecursive($trait);
        }
        return $traits;
    }

    /**
     * Get the class "basename" of the given object / class.
     *
     * @param string|object $class
     * @return string
     */
    public static function classBasename($class)
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }

    public static function isImplements($className, $interfaceName)
    {
        $list = class_implements($className);
        return in_array($interfaceName, $list);
    }

    public static function getDescriptionByField($field)
    {
        $ret = $field;
        if (substr($field, -1) === 's') {
            $ret .= "es";
        } else {
            $ret .= "s";
        }
        return $ret;
    }

    public static function getMethodInfo($class, $method)
    {
        $refMethod = new \ReflectionMethod($class, $method);
        $refParams = $refMethod->getParameters();
        $ret = null;
        if ($refMethod->class === ltrim($class, "\\")) {
            $params = [];
            foreach ($refParams as $param) {
                $params[] = $param->getName();
            }
            $description = "";
            if (preg_match("#([^@$]*)#", $refMethod->getDocComment(), $d) && !empty($d[1])) {
                $description = trim(str_replace(["/**", "*/", "*"], "", $d[1]));
            }
            $ret = [
                'name' => $method,
                'description' => $description,
                'params' => $params
            ];
        }
        return $ret;
    }

    /**
     * Match methods by mask from target class
     *
     * @param string $class
     * @param string $methodMask
     * @param bool $methodMaskInvert
     * @return array<int, array{name: string, description: string, params: array<int, string>}> Array of method info
     * @throws \ReflectionException
     */
    public static function matchClassMethods(string $class, string $methodMask, bool $methodMaskInvert = false)
    {
        $ret = [];
        $methods = get_class_methods($class);
        foreach ($methods as $k => $method) {
            if ($method === "__construct") {
                continue;
            }
            $result = preg_match('#' . $methodMask . '#s', $method);
            if ($methodMaskInvert) {
                $result = !$result;
            }
            if ($result) {
                $info = self::getMethodInfo($class, $method);
                if ($info) {
                    $ret[] = $info;
                }
            } else {
                unset($methods[$k]);
            }
        }
        return $ret;
    }
}
