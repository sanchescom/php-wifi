<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Nmcli;

use Sanchescom\WiFi\Parser\NetworkParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;
use Sanchescom\WiFi\Value\Signal;

/**
 * Parses `nmcli --terse --fields active,ssid,bssid,mode,chan,freq,signal,security,
 * wpa-flags,rsn-flags device wifi list`.
 */
final class ListParser implements NetworkParser
{
    public function parse(string $output): array
    {
        $networks = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = $this->fields($line);

            if (count($fields) !== 10) {
                continue;
            }

            [$active, $ssid, $bssid, , $chan, $freq, $signal, $security, $wpa, $rsn] = $fields;

            $frequency = (int) $freq;
            $channel = is_numeric($chan) ? (int) $chan : null;

            $networks[] = new Network(
                ssid: $ssid,
                ssidHidden: $ssid === '',
                bssid: Bssid::tryFrom($bssid),
                channel: $channel,
                band: $frequency > 0 ? Band::fromFrequency($frequency) : null,
                frequency: $frequency > 0 ? $frequency : null,
                signal: is_numeric($signal) ? Signal::fromQuality((float) $signal) : null,
                security: Security::fromDescription($security),
                securityFlags: trim($wpa . ' ' . $rsn),
                connected: $active === 'yes',
            );
        }

        return $networks;
    }

    /**
     * Split one `nmcli --terse` line into its fields. nmcli escapes a literal
     * ':' inside a field as '\:', so split on colons that are not preceded by
     * a backslash and un-escape each field afterwards.
     *
     * @return list<string>
     */
    private function fields(string $line): array
    {
        $fields = preg_split('/(?<!\\\\):/', $line) ?: [];

        return array_map(
            static fn (string $field): string => trim(str_replace('\\:', ':', $field)),
            $fields,
        );
    }
}
