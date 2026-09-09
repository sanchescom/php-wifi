<?php

if (!function_exists('trim_first')) {
    /**
     * Trimming first element in each array.
     *
     * @param array<int, array<int, string>> $array
     *
     * @return array<int, string>
     */
    function trim_first(array $array): array
    {
        array_walk($array, function (&$item) {
            $firstElement = $item[0] ?? null;

            $item = $firstElement !== null ? trim($firstElement) : null;
        });

        return $array;
    }
}
