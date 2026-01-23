<?php

if (!function_exists('trim_first')) {
    /**
     * Trimming first element in each array.
     *
     * @param array $array
     *
     * @return array
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
