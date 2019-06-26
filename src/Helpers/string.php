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

if (!function_exists('glue_commands')) {
    /**
     * Glue commands.
     *
     * @param string ...$commands
     *
     * @return string
     */
    function glue_commands(string ...$commands): string
    {
        return implode(' && ', $commands);
    }
}

if (!function_exists('extract_after')) {
    /**
     * Get string content after.
     *
     * @param string $string
     * @param string $char
     *
     * @return string
     */
    function extract_after(string $string, string $char = ":"): string
    {
        $title = strtok($string, $char) ?: '';
        $value = substr($string, strlen($title));

        return trim(ltrim($value, $char));
    }
}
