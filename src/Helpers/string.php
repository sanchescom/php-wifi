<?php

if (!function_exists('extract_bssid')) {
    /**
     * Extracting bssid from passed string.
     *
     * @param string $string
     * @param int    $matchColumn
     *
     * @return array
     */
    function extract_bssid(string $string, int $matchColumn): array
    {
        $extractedBssid = [];

        preg_match_all(
            '/(\w{2}:\w{2}:\w{2}:\w{2}:\w{2}:\w{2})/',
            $string,
            $extractedBssid
        );

        return array_unique(array_column($extractedBssid, $matchColumn));
    }
}

if (!function_exists('to_hex')) {
    /**
     * Converting string to hex.
     *
     * @param string $string
     *
     * @return string
     */
    function to_hex(string $string): string
    {
        $len = strlen($string);
        $hex = '';

        for ($i = 0; $i < $len; $i++) {
            $hex .= substr('0'.dechex(ord($string[$i])), -2);
        }

        return strtoupper($hex);
    }
}
