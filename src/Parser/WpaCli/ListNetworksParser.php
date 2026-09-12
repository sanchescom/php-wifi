<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\WpaCli;

use Sanchescom\WiFi\Value\KnownNetwork;

/**
 * Parses `wpa_cli -i <iface> list_networks`: a tab-separated table with
 * header `network id / ssid / bssid / flags`.
 *
 * wpa_supplicant has no notion of a saved connection name distinct from the
 * SSID, so `KnownNetwork::$name` is set to the SSID. It also never reports
 * which network interface owns a configured network, so
 * `KnownNetwork::$device` is always null here.
 */
final class ListNetworksParser
{
    /** @return list<KnownNetwork> */
    public function parse(string $output): array
    {
        $networks = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = explode("\t", $line, 4);

            if (count($fields) < 3) {
                continue;
            }

            $ssid = $fields[1];
            $flags = $fields[3] ?? '';

            $networks[] = new KnownNetwork(
                name: $ssid,
                ssid: $ssid,
                device: null,
                active: str_contains($flags, '[CURRENT]'),
            );
        }

        return $networks;
    }
}
