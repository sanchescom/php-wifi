<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Darwin;

use Sanchescom\WiFi\Parser\NetworkParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;
use Sanchescom\WiFi\Value\Signal;

/**
 * Parses a single `system_profiler SPAirPortDataType` run. Both the current
 * network and the other-networks list are present in one run, so — unlike
 * the 2.x command, which ran `system_profiler` twice with a `--separator--`
 * between the runs — this parser takes one run's output directly.
 */
final class SystemProfilerParser implements NetworkParser
{
    private const REDACTED = '<redacted>';

    /** True when the output is the legacy `airport` scan table, not `system_profiler`. */
    public static function looksLikeAirport(string $output): bool
    {
        return str_contains($output, 'SSID BSSID');
    }

    public function parse(string $output): array
    {
        $slice = self::wifiInterfaceSlice($output);
        $current = self::extractCurrentSsid($slice);

        return self::parseNetworks($slice, $current);
    }

    /**
     * The slice of `system_profiler` output belonging to the first Wi-Fi
     * interface (the first block with a `Card Type:` line), from that line
     * up to (not including) the next 8-space-indented interface header.
     * Returns '' when no interface has a `Card Type:` line.
     */
    private static function wifiInterfaceSlice(string $output): string
    {
        $lines = explode("\n", $output);
        $start = null;
        $end = count($lines);

        foreach ($lines as $index => $line) {
            if ($start === null) {
                if (preg_match('/^\s{10}Card Type:/', $line) === 1) {
                    $start = $index;
                }

                continue;
            }

            // 8-space indent = interface header ("en0:", "awdl0:")
            if (preg_match('/^\s{8}\S+:\s*$/', $line) === 1) {
                $end = $index;
                break;
            }
        }

        if ($start === null) {
            return '';
        }

        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    private static function extractCurrentSsid(string $slice): ?string
    {
        if (preg_match('/Current Network Information:\s*\n\s*(.+?):/m', $slice, $matches) !== 1) {
            return null;
        }

        $ssid = trim($matches[1]);

        return $ssid === self::REDACTED ? null : $ssid;
    }

    /** @return list<Network> */
    private static function parseNetworks(string $slice, ?string $current): array
    {
        $networks = [];

        $ssid = null;
        $channel = null;
        $band = null;
        $security = '';
        $signal = null;

        foreach (explode("\n", $slice) as $line) {
            if (preg_match('/^\s{12}(.+?):\s*$/', $line, $matches) === 1) {
                if ($ssid !== null) {
                    $networks[] = self::buildNetwork($ssid, $channel, $band, $security, $signal, $current);
                }

                $ssid = trim($matches[1]);
                $channel = null;
                $band = null;
                $security = '';
                $signal = null;

                continue;
            }

            if ($ssid === null || preg_match('/^\s{14}(.+?):\s*(.+)$/', $line, $matches) !== 1) {
                continue;
            }

            $key = trim($matches[1]);
            $value = trim($matches[2]);

            if ($key === 'Channel' && preg_match('/^(\d+)(?:\s*\((\d+(?:\.\d+)?)GHz)?/', $value, $channelMatch) === 1) {
                $channel = (int) $channelMatch[1];
                $band = Band::fromHint($channelMatch[2] ?? null);
            } elseif ($key === 'Security') {
                $security = $value;
            } elseif ($key === 'Signal / Noise' && preg_match('/^(-?\d+)\s*dBm/', $value, $signalMatch) === 1) {
                $signal = Signal::fromDbm((float) $signalMatch[1]);
            }
        }

        if ($ssid !== null) {
            $networks[] = self::buildNetwork($ssid, $channel, $band, $security, $signal, $current);
        }

        return $networks;
    }

    private static function buildNetwork(
        string $ssid,
        ?int $channel,
        ?Band $band,
        string $security,
        ?Signal $signal,
        ?string $current,
    ): Network {
        $hidden = $ssid === self::REDACTED;

        return new Network(
            ssid: $ssid,
            ssidHidden: $hidden,
            bssid: null,
            channel: $channel,
            band: $band,
            frequency: $channel !== null ? $band?->frequencyForChannel($channel) : null,
            signal: $signal,
            security: Security::fromDescription($security),
            securityFlags: '',
            connected: !$hidden && $current !== null && $ssid === $current,
        );
    }
}
