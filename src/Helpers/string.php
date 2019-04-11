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
