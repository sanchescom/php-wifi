<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Darwin;

use Sanchescom\WiFi\Value\Device;

/**
 * Parses `networksetup -listallhardwareports` and returns the device behind
 * the Wi-Fi hardware port ("Wi-Fi" on current macOS, "AirPort" on older
 * releases), or null when none is present.
 */
final class HardwarePortsParser
{
    public function parse(string $output): ?Device
    {
        $expectDevice = false;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if ($expectDevice) {
                if (preg_match('/^Device:\s*(.+)$/', $line, $matches) === 1) {
                    return new Device(trim($matches[1]));
                }

                $expectDevice = false;
                continue;
            }

            if (preg_match('/^Hardware Port:\s*(Wi-Fi|AirPort)\s*$/', $line) === 1) {
                $expectDevice = true;
            }
        }

        return null;
    }
}
