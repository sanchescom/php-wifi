<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\WpaCli;

/**
 * Parses `wpa_cli -i <iface> status`: `key=value` lines.
 *
 * The Linux backend reads four of these keys:
 * - `wpa_state` — e.g. `COMPLETED`, to decide whether the connection is up.
 * - `ssid` — the SSID of the currently associated network.
 * - `bssid` — the BSSID of the currently associated access point.
 * - `ip_address` — the address DHCP assigned on the interface.
 *
 * Every other key wpa_supplicant prints (`freq`, `id`, `mode`, `address`, ...)
 * is kept in the returned map as well, unclassified.
 *
 * The `ssid` value is decoded through {@see Printf} since wpa_supplicant
 * printf-escapes non-printable-ASCII bytes there; every other key is passed
 * through verbatim.
 */
final class StatusParser
{
    /** @return array<string, string> */
    public function parse(string $output): array
    {
        $status = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $status[$key] = $key === 'ssid' ? Printf::decode($value) : $value;
        }

        return $status;
    }
}
