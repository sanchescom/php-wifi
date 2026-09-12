<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\WpaCli;

use Sanchescom\WiFi\Parser\NetworkParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;
use Sanchescom\WiFi\Value\Signal;

/**
 * Parses `wpa_cli -i <iface> scan_results`: a tab-separated table with header
 * `bssid / frequency / signal level / flags / ssid`. wpa_cli never reports a
 * connected/active row here, so every {@see Network} comes back with
 * `connected: false`.
 */
final class ScanResultsParser implements NetworkParser
{
    public function parse(string $output): array
    {
        $networks = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = explode("\t", $line, 5);

            if (count($fields) < 5) {
                continue;
            }

            [$bssid, $freq, $signal, $flags, $ssid] = $fields;

            $frequency = is_numeric($freq) ? (int) $freq : null;
            $band = null;
            $channel = null;

            if ($frequency !== null) {
                $band = Band::fromFrequency($frequency);
                $channel = $band !== null ? self::channelFor($band, $frequency) : null;
            }

            $networks[] = new Network(
                ssid: $ssid,
                ssidHidden: $ssid === '',
                bssid: Bssid::tryFrom($bssid),
                channel: $channel,
                band: $band,
                frequency: $frequency,
                signal: is_numeric($signal) ? Signal::fromDbm((float) $signal) : null,
                security: self::classifySecurity($flags),
                securityFlags: $flags,
                connected: false,
            );
        }

        return $networks;
    }

    /**
     * `Security::fromDescription()` classifies by substring match on
     * WPA3/WPA2/WPA/WEP. wpa_cli's `[ESS]`-only flag string (an open network)
     * contains none of those, so route it through the same "empty string
     * means Open" rule the function already has, rather than teaching the
     * shared classifier about wpa_supplicant's bracket syntax.
     */
    private static function classifySecurity(string $flags): Security
    {
        if (!str_contains($flags, 'WPA') && !str_contains($flags, 'WEP')) {
            return Security::fromDescription('');
        }

        return Security::fromDescription($flags);
    }

    /**
     * Reverses `Band::frequencyForChannel()` by searching the band's
     * plausible channel numbers for the one that reproduces $frequency,
     * rather than re-deriving the channel arithmetic here.
     */
    private static function channelFor(Band $band, int $frequency): ?int
    {
        $lastChannel = match ($band) {
            Band::GHz2_4 => 14,
            Band::GHz5 => 173,
            Band::GHz6 => 233,
        };

        for ($channel = 1; $channel <= $lastChannel; $channel++) {
            if ($band->frequencyForChannel($channel) === $frequency) {
                return $channel;
            }
        }

        return null;
    }
}
