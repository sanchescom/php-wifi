<?php

declare(strict_types=1);

namespace Sanchescom\Wifi\System\Mac;

use Sanchescom\Wifi\System\AbstractNetworksCollection;
use Sanchescom\Wifi\System\NetworksCollectionTrait;
use Sanchescom\Wifi\System\UtilityInterface;

class NetworksCollection extends AbstractNetworksCollection implements UtilityInterface
{
    use NetworksCollectionTrait;

    /**
     * @var int
     */
    const BSSID_KEY = 1;

    /**
     * @return string
     */
    public function getUtility()
    {
        return '/System/Library/PrivateFrameworks/Apple80211.framework/Versions/Current/Resources/airport';
    }

    /**
     * @return string
     */
    protected function getCommand(): string
    {

        return implode(' && ', [
            $this->getUtility() . ' -s',
            'echo "' . $this->separator . '"',
            $this->getUtility() . ' --getinfo'
        ]);
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
     * @return array
     */
    public function extractingNetworks($output): array
    {
        list($networks, $current) = $this->explodeOutput($output);

        $currentBssid = $this->extractBssid($current, 0);

        $availableNetworks = explode("\n", trim($networks));

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
     * @return array
     */
    protected function extractingDataFromString(string $networkData): array
    {
        $extractedProperties = [];

        $pattern = '/(.*?)' .
        '(\w{2}:\w{2}:\w{2}:\w{2}:\w{2}:\w{2})\s{1,}' .
        '([-+]?[0-9]*)\s{1,}' .
        '([^a-zA-Z]*)' .
        '(\w{1,})\s{1,}' .
        '([\w-]+)' .
        '(.*)/';

        preg_match_all(
            $pattern,
            $networkData,
            $extractedProperties
        );

        array_shift($extractedProperties);

        return $this->trimArrayValue($extractedProperties);
    }
}
