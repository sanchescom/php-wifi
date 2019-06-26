<?php

if (!function_exists('to_dbm')) {
    /**
     * Converting quality to dbm.
     *
     * @param int $quality
     *
     * @return float
     */
    function to_dbm(int $quality): float
    {
        return ($quality / 2) - 100;
    }
}

if (!function_exists('to_quality')) {
    /**
     * Converting dbm to quality.
     *
     * @param int $dbm
     *
     * @return float
     */
    function to_quality(int $dbm): float
    {
        return 2 * ($dbm + 100);
    }
}
