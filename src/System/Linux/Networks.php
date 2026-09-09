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

    public function extractingNetworks(string $output): array
    {
        $lines = array_filter(
            $this->explodeAvailableNetworks($output),
            static fn (string $line): bool => $line !== '' && $line[0] !== '#'
        );

        return array_values(array_filter(array_map([$this, 'extractingDataFromString'], $lines)));
    }

    /**
     * Split one `nmcli --terse` line into its ten fields. nmcli escapes a
     * literal ':' inside a field as '\:', so split on colons that are not
     * preceded by a backslash and un-escape each field afterwards.
     *
     * @return array<int, string>
     */
    protected function extractingDataFromString(string $networkData): array
    {
        $fields = preg_split('/(?<!\\\\):/', $networkData) ?: [];

        $fields = array_map(
            static fn (string $field): string => trim(str_replace('\\:', ':', $field)),
            $fields
        );

        return count($fields) === 10 ? $fields : [];
    }
}
