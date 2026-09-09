<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Windows;

use Sanchescom\WiFi\Parser\NetworkParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;
use Sanchescom\WiFi\Value\Signal;

/**
 * Parses `netsh wlan show networks mode=Bssid`:
 *
 *     SSID 1 : AlphaNet-foiEmE
 *         Network type            : Infrastructure
 *         Authentication          : WPA2-Personal
 *         Encryption              : CCMP
 *         BSSID 1                 : 04:8d:38:22:78:9e
 *              Signal             : 99%
 *              Radio type         : 802.11n
 *              Channel            : 7
 *              Basic rates (Mbps) : ...
 *              Other rates (Mbps) : ...
 *
 * One network per `SSID N :` block, using the first BSSID/Signal/Channel found
 * in it. The command output alone never says which network is connected —
 * the backend cross-references it against `InterfacesParser` output.
 */
final class NetworksParser implements NetworkParser
{
    private const SSID_HEADER = '/^SSID\s+\d+\s*:\s*(.*)$/';

    private const FIELD = '/^\s*(Authentication|Encryption|BSSID\s+\d+|Signal|Channel)\s*:\s*(.+?)\s*$/';

    public function parse(string $output): array
    {
        $networks = [];
        $ssid = null;
        /** @var array<string, string> $fields */
        $fields = [];

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            if (preg_match(self::SSID_HEADER, $line, $matches) === 1) {
                if ($ssid !== null) {
                    $networks[] = self::buildNetwork($ssid, $fields);
                }

                $ssid = $matches[1];
                $fields = [];
                continue;
            }

            if ($ssid === null) {
                continue;
            }

            if (preg_match(self::FIELD, $line, $matches) === 1) {
                $key = str_starts_with($matches[1], 'BSSID') ? 'BSSID' : $matches[1];

                $fields[$key] ??= $matches[2];
            }
        }

        if ($ssid !== null) {
            $networks[] = self::buildNetwork($ssid, $fields);
        }

        return $networks;
    }

    /** @param array<string, string> $fields */
    private static function buildNetwork(string $ssid, array $fields): Network
    {
        $bssid = isset($fields['BSSID']) ? Bssid::tryFrom($fields['BSSID']) : null;
        $channel = isset($fields['Channel']) && is_numeric($fields['Channel']) ? (int) $fields['Channel'] : null;
        $band = null;
        $frequency = null;

        if ($channel !== null) {
            $band = $channel <= 14 ? Band::GHz2_4 : Band::GHz5;
            $frequency = $band->frequencyForChannel($channel);
        }

        $signal = isset($fields['Signal']) && preg_match('/(\d+(?:\.\d+)?)/', $fields['Signal'], $m) === 1
            ? Signal::fromQuality((float) $m[1])
            : null;

        return new Network(
            ssid: $ssid,
            ssidHidden: $ssid === '',
            bssid: $bssid,
            channel: $channel,
            band: $band,
            frequency: $frequency,
            signal: $signal,
            security: Security::fromDescription($fields['Authentication'] ?? ''),
            securityFlags: $fields['Encryption'] ?? '',
            connected: false,
        );
    }
}
