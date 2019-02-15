<?php

declare(strict_types=1);

namespace Sanchescom\Wifi\System\Windows;

use Sanchescom\Wifi\System\AbstractNetworksCollection;
use Sanchescom\Wifi\System\NetworksCollectionTrait;

/**
 * Class NetworksCollection
 * @inheritdoc
 * @package Sanchescom\Wifi\System\Windows
 */
class NetworksCollection extends AbstractNetworksCollection
{
    use NetworksCollectionTrait, UtilityTrait;

    /**
     * @var int
     */
    const BSSID_KEY = 4;

    /**
     * @var int
     */
    const ZERO_KEY = 0;

    /**
     * @return string
     */
    protected function getCommand(): string
    {
        return implode(' && ', [
            'chcp 65001',
            $this->getUtility() . ' show networks mode=Bssid',
            'echo ' . $this->separator,
            $this->getUtility() . ' show interfaces'
        ]);
    }

    /**
     * @return string
     */
    protected function getNetwork(): ?string
    {
        return Network::class;
    }

    /**
     * @param string $output
     * @return array
     */
    protected function extractingNetworks(string $output): array
    {
        list($networks, $current) = $this->explodeOutput($output);

        $currentBssid = $this->extractBssid($current, 1);

        $availableNetworks = $this->explodeAvailableNetworks($networks);

        $groupedNetworks = [];

        for ($i = 10, $j = 5, $k = 0; count($availableNetworks) >= $j; $i--, $j++) {
            if ($i == self::ZERO_KEY) {
                if ($this->isConnected($groupedNetworks[$k][self::BSSID_KEY], $currentBssid)) {
                    $groupedNetworks[$k][] = true;
                }
                $i = 11;
                $k++;
                continue;
            }
            $groupedNetworks[$k][] = $this->extractingDataFromString($availableNetworks[$j]);
        }

        return $groupedNetworks;
    }

    /**
     * @param $row
     * @return string
     */
    private function extractingDataFromString($row)
    {
        $title = strtok($row, ':') ?: '';
        $value = substr($row, strlen($title));

        return trim(ltrim($value, ':'));
    }
}
