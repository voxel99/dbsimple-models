<?php

namespace Jam\Models\Utils;

use Exception;

class Ip
{
    public static function validate($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    }

    /**
     * dtr_pton
     *
     * Converts a printable IP into an unpacked binary string
     *
     * @author Mike Mackintosh - mike@bakeryphp.com
     * @param string $ip
     * @return string $bin
     */
    public static function pton($ip)
    {
        if (static::validate($ip)) {
            return inet_pton($ip);
        }
        throw new Exception("Please supply a valid IPv4 or IPv6 address");
    }

    /**
     * dtr_ntop
     *
     * Converts an unpacked binary string into a printable IP
     *
     * @author Mike Mackintosh - mike@bakeryphp.com
     * @param string $str
     * @return string $ip
     */
    public static function ntop($str)
    {
        return inet_ntop($str);
    }
}
