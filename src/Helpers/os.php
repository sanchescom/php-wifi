<?php

if (!function_exists('os_prefix')) {
    /**
     * Getting prefix from operation system name.
     *
     * @return string
     */
    function os_prefix()
    {
        return strtoupper(substr(PHP_OS, 0, 3));
    }
}
