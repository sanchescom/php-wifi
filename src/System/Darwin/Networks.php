<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Darwin;

use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\AbstractNetworks;
use Sanchescom\WiFi\System\Separable;

/**
 * Class Networks.
 */
class Networks extends AbstractNetworks
{
    use Separable;

    /**
     * @var int
     */
    const BSSID_KEY = 1;

    /**
     * What macOS prints instead of an SSID when the calling process has no
     * Location Services permission.
     *
     * @var string
     */
    const REDACTED = '<redacted>';

    /**
     * Get the WiFi scan command for macOS.
     * Uses system_profiler which is the official macOS tool.
     *
     * @return string
     */
    protected function getCommand(): string
    {
        // system_profiler is the official macOS tool for WiFi info
        return sprintf(
            'system_profiler SPAirPortDataType 2>/dev/null && echo "%s" && '.
            'system_profiler SPAirPortDataType 2>/dev/null',
            $this->separator
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getNetwork(): AbstractNetwork
    {
        return new Network($this->command);
    }

    /**
     * Extract networks from command output.
     * Supports both airport and system_profiler formats.
     *
     * @param string $output
     *
     * @return array
     */
    public function extractingNetworks($output): array
    {
        [$networks, $current] = $this->explodeOutput($output);

        // Detect format: if it starts with "SSID BSSID" it's airport format
        if (strpos($networks, 'SSID BSSID') !== false) {
            // Old airport format
            return $this->parseAirportNetworks($networks, $current);
        } else {
            // New system_profiler format
            $currentSSID = $this->extractCurrentSSID($current);
            return $this->parseSystemProfilerNetworks($networks, $currentSSID);
        }
    }

    /**
     * Parse networks from old airport format (for backward compatibility).
     *
     * @param string $networks
     * @param string $current
     * @return array
     */
    protected function parseAirportNetworks(string $networks, string $current): array
    {
        $currentBssid = extract_bssid($current, 0);
        $availableNetworks = $this->explodeAvailableNetworks($networks);

        // Remove header line
        if (!empty($availableNetworks)) {
            array_shift($availableNetworks);
        }

        array_walk($availableNetworks, function (&$networkData) use ($currentBssid) {
            $networkData = $this->extractingDataFromAirportString($networkData);

            if ($networkData && in_array($networkData[self::BSSID_KEY] ?? '', $currentBssid)) {
                $networkData[] = true;
            }
        });

        return array_filter($availableNetworks);
    }

    /**
     * Extract network data from airport format string.
     *
     * @param string $networkData
     * @return array
     */
    protected function extractingDataFromAirportString(string $networkData): array
    {
        $extractedProperties = [];

        $pattern = '/(.*?)'.
        '(\w{2}:\w{2}:\w{2}:\w{2}:\w{2}:\w{2})\s{1,}'.
        '([-+]?[0-9]*)\s{1,}'.
        '([^a-zA-Z]*)'.
        '(\w{1,})\s{1,}'.
        '([\w-]+)'.
        '(.*)/';

        preg_match_all(
            $pattern,
            $networkData,
            $extractedProperties
        );

        array_shift($extractedProperties);

        return trim_first($extractedProperties);
    }

    /**
     * Extract current network SSID from output.
     *
     * Scoped to the same Wi-Fi interface slice used for network parsing, so
     * a "Current Network Information:" block belonging to another interface
     * (e.g. awdl0) is never mistaken for the Wi-Fi interface's own.
     *
     * @param string $output
     * @return string|null
     */
    protected function extractCurrentSSID(string $output): ?string
    {
        $wifiInterface = $this->wifiInterfaceSlice($output);

        if (preg_match('/Current Network Information:\s*\n\s*(.+?):/m', $wifiInterface, $matches)) {
            $ssid = trim($matches[1]);

            return $ssid === self::REDACTED ? null : $ssid;
        }

        return null;
    }

    /**
     * Return the slice of `system_profiler` output belonging to the first
     * Wi-Fi interface (the first block with a `Card Type:` line), from that
     * line up to (not including) the next 8-space-indented interface
     * header. Returns '' when no interface has a `Card Type:` line.
     *
     * @param string $output
     * @return string
     */
    protected function wifiInterfaceSlice(string $output): string
    {
        $lines = explode("\n", $output);
        $start = null;
        $end = count($lines);

        foreach ($lines as $index => $line) {
            if ($start === null) {
                if (preg_match('/^\s{10}Card Type:/', $line)) {
                    $start = $index;
                }
                continue;
            }
            // 8-space indent = interface header ("en0:", "awdl0:")
            if (preg_match('/^\s{8}\S+:\s*$/', $line)) {
                $end = $index;
                break;
            }
        }

        if ($start === null) {
            return '';
        }

        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    /**
     * Parse networks from system_profiler format.
     *
     * @param string $output
     * @param string|null $currentSSID
     * @return array
     */
    protected function parseSystemProfilerNetworks(string $output, ?string $currentSSID): array
    {
        $networks = [];
        $lines = explode("\n", $this->wifiInterfaceSlice($output));
        $currentNetwork = null;

        foreach ($lines as $line) {
            // Match network name (it's at the beginning of a block, followed by :)
            if (preg_match('/^\s{12}(.+?):\s*$/', $line, $matches)) {
                // Save previous network if exists
                if ($currentNetwork && !empty($currentNetwork['ssid'])) {
                    $networks[] = $this->formatNetworkData($currentNetwork, $currentSSID);
                }
                // Start new network
                $currentNetwork = ['ssid' => trim($matches[1])];
            } elseif ($currentNetwork && preg_match('/^\s{14}(.+?):\s*(.+)$/', $line, $matches)) {
                // Parse network properties
                $key = trim($matches[1]);
                $value = trim($matches[2]);

                switch ($key) {
                    case 'Channel':
                        // Extract channel number: "157 (5GHz, 80MHz)" -> 157
                        if (preg_match('/^(\d+)/', $value, $channelMatch)) {
                            $currentNetwork['channel'] = $channelMatch[1];
                        }
                        break;
                    case 'Security':
                        $currentNetwork['security'] = $value;
                        break;
                    case 'Signal / Noise':
                        // Extract signal: "-37 dBm / -94 dBm" -> -37
                        if (preg_match('/^(-?\d+)\s*dBm/', $value, $signalMatch)) {
                            $currentNetwork['signal'] = $signalMatch[1];
                        }
                        break;
                }
            }
        }

        // Don't forget the last network
        if ($currentNetwork && !empty($currentNetwork['ssid'])) {
            $networks[] = $this->formatNetworkData($currentNetwork, $currentSSID);
        }

        return $networks;
    }

    /**
     * Format network data into expected array format.
     *
     * @param array $network
     * @param string|null $currentSSID
     * @return array
     */
    protected function formatNetworkData(array $network, ?string $currentSSID): array
    {
        $ssid = $network['ssid'] ?? '';
        $signal = $network['signal'] ?? '-100';
        $channel = $network['channel'] ?? '0';
        $security = $network['security'] ?? 'Unknown';

        // Format: [ssid, bssid, signal, channel, ?, ?, security, connected?]
        $formatted = [
            $ssid,                    // 0: SSID
            '',                       // 1: BSSID (not available in system_profiler)
            $signal,                  // 2: Signal/Quality
            $channel,                 // 3: Channel
            '',                       // 4: unused
            '',                       // 5: Security flags
            $security,                // 6: Security
        ];

        // Mark as connected if this is the current network
        if ($currentSSID !== null && $ssid !== self::REDACTED && $ssid === $currentSSID) {
            $formatted[] = true;
        }

        return $formatted;
    }
}
