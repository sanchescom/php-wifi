<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Linux;

use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\AbstractNetworks;

/**
 * Class Networks.
 */
class Networks extends AbstractNetworks
{
    /**
     * @var int
     */
    public const BSSID_KEY = 0;

    /**
     * @return string
     */
    protected function getCommand(): string
    {
        return 'LANG=C nmcli '
            . ' --terse'
            . ' --fields '
            . 'active,ssid,bssid,'
            . 'mode,chan,freq,'
            . 'signal,security,wpa-flags,'
            . 'rsn-flags'
            . ' device'
            . ' wifi'
            . ' list';
    }

    /**
     * {@inheritdoc}
     */
    protected function getNetwork(): AbstractNetwork
    {
        return new Network($this->command);
    }

    /**
     * @param string $output
     *
     * @return array<int, array<int, string>>
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
     *
     * @return array<int, string>
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

        return trim_first($extractedProperties);
    }
}
