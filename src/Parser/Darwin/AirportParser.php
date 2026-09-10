<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Darwin;

use Sanchescom\WiFi\Parser\NetworkParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;
use Sanchescom\WiFi\Value\Signal;

/**
 * Parses the legacy `airport -s` scan table:
 *
 *     SSID BSSID             RSSI CHANNEL HT CC SECURITY (auth/unicast/group)
 *     Some Network 04:8d:38:22:78:9e -83  100,+1  Y  AU WPA2(PSK/AES/AES)
 *
 * Input is the table only, with no `--separator--` / current-network dump —
 * the table alone never says which network is connected.
 */
final class AirportParser implements NetworkParser
{
    private const ROW_PATTERN = '/^(.*?)' .
        '(\w{2}:\w{2}:\w{2}:\w{2}:\w{2}:\w{2})\s+' .
        '([-+]?[0-9]+)\s+' .
        '([\w,+]+)\s+' .
        '(\w+)\s+' .
        '([\w-]+)\s+' .
        '(.*)$/';

    public function parse(string $output): array
    {
        $networks = [];

        foreach (explode("\n", $output) as $line) {
            if (trim($line) === '' || str_contains($line, 'SSID BSSID')) {
                continue;
            }

            if (preg_match(self::ROW_PATTERN, $line, $matches) !== 1) {
                continue;
            }

            $network = self::buildNetwork($matches);

            if ($network !== null) {
                $networks[] = $network;
            }
        }

        return $networks;
    }

    /** @param list<string> $matches */
    private static function buildNetwork(array $matches): ?Network
    {
        $ssid = trim($matches[1]);
        $bssid = Bssid::tryFrom($matches[2]);
        $rssi = $matches[3];
        $channelRaw = $matches[4];
        $security = trim($matches[7]);

        if ($bssid === null || preg_match('/^(\d+)/', $channelRaw, $channelMatch) !== 1) {
            return null;
        }

        $channel = (int) $channelMatch[1];
        $band = $channel <= 14 ? Band::GHz2_4 : Band::GHz5;

        return new Network(
            ssid: $ssid,
            ssidHidden: $ssid === '',
            bssid: $bssid,
            channel: $channel,
            band: $band,
            frequency: $band->frequencyForChannel($channel),
            signal: is_numeric($rssi) ? Signal::fromDbm((float) $rssi) : null,
            security: Security::fromDescription($security),
            securityFlags: $security,
            // The scan table alone never says which network is connected.
            connected: false,
        );
    }
}
