<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Mac;

use Sanchescom\WiFi\System\AbstractNetworksCollection;
use Sanchescom\WiFi\System\Separable;

/**
 * Class NetworksCollection.
 */
class NetworksCollection extends AbstractNetworksCollection
{
    use Separable;

    /**
     * @var int
     */
    const BSSID_KEY = 1;

    /**
     * @return string
     */
    protected function getCommand(): string
    {
        $utility = '/System/Library/PrivateFrameworks/Apple80211.framework/Versions/Current/Resources/airport';

        return sprintf('%s -s && echo "%s" && %s --getinfo', $utility, $this->separator, $utility);
    }

    /**
     * @return string
     */
    protected function getNetwork():? string
    {
        return Network::class;
    }

    /**
     * @param string $output
     *
     * @return array
     */
    public function extractingNetworks($output): array
    {
        list($networks, $current) = $this->explodeOutput($output);

        $currentBssid = extract_bssid($current, 0);

        $availableNetworks = $this->explodeAvailableNetworks($networks);

        array_shift($availableNetworks);

        array_walk($availableNetworks, function (&$networkData) use ($currentBssid) {
            $networkData = $this->extractingDataFromString($networkData);

            if (in_array($networkData[self::BSSID_KEY], $currentBssid)) {
                array_push($networkData, true);
            }
        });

        return $availableNetworks;
    }

    /**
     * @param string $networkData
     *
     * @return array
     */
    protected function extractingDataFromString(string $networkData): array
    {
        $extractedProperties = [];

        $pattern = '/(.*?)'.
        '(\w{2}:\w{2}:\w{2}:\w{2}:\w{2}:\w{2})\s{1,}'.
        '([-+]?[0-9]*)\s{1,}'.
        '([^a-zA-Z]*)'.
        '(\w{1,})\s{1,}'.
        '([\w-]+)'.
        '(.*)/';

        preg_match_all(
            $pattern,
            $networkData,
            $extractedProperties
        );

        array_shift($extractedProperties);

        return trim_first($extractedProperties);
    }
}
