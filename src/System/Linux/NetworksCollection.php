<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Linux;

use Sanchescom\WiFi\System\AbstractNetworksCollection;
use Sanchescom\WiFi\System\NetworksCollectionTrait;

class NetworksCollection extends AbstractNetworksCollection
{
    use NetworksCollectionTrait, UtilityTrait;

    const BSSID_KEY = 0;

    /**
     * @return string
     */
    protected function getCommand(): string
    {
        $options =
            ' --terse' .
            ' --fields ' .
            'active, ssid, bssid, ' .
            'mode, chan, freq, ' .
            'signal, security, wpa-flags, ' .
            'rsn-flags' .
            ' device' .
            ' wifi' .
            ' list';
        return implode(' && ', [
            $this->getUtility() . $options,
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
        $availableNetworks = $this->explodeAvailableNetworks($output);

        array_walk($availableNetworks, function (&$networkData) {
            $networkData = $this->extractingDataFromString($networkData);
        });

        return $availableNetworks;
    }

    /**
     * @param string $networkData
     * @return array
     */
    protected function extractingDataFromString($networkData): array
    {
        $extractedProperties = [];

        preg_match_all(
            '/(.*):(.*):(\w{2}\:\w{2}\:\w{2}\:\w{2}\:\w{2}:\w{2}):(.*):(.*):(.*):(.*):(.*):(.*):(.*)/',
            str_replace('\\:', ':', $networkData),
            $extractedProperties
        );

        array_shift($extractedProperties);

        return $this->trimArrayValue($extractedProperties);
    }
}
