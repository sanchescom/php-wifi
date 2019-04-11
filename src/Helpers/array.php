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
            list($firstElement) = $item;

            $item = trim($firstElement);
        });

        return $array;
    }
}
