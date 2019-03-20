<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

trait NetworksCollectionTrait
{
    protected $separator = "--separator--";

    /**
     * @param array $array
     *
     * @return array
     */
    protected function trimArrayValue(array $array): array
    {
        array_walk($array, function (&$item) {
            list($firstElement) = $item;

            $item = trim($firstElement);
        });

        return $array;
    }

    /**
     * @param string $string
     * @param int $matchColumn
     *
     * @return array
     */
    protected function extractBssid(string $string, int $matchColumn): array
    {
        $extractedBssid = [];

        preg_match_all(
            '/(\w{2}:\w{2}:\w{2}:\w{2}:\w{2}:\w{2})/',
            $string,
            $extractedBssid
        );

        return array_unique(array_column($extractedBssid, $matchColumn));
    }

    /**
     * @param string $output
     *
     * @return array
     */
    protected function explodeOutput(string $output): array
    {
        return explode($this->separator, $output);
    }
}
