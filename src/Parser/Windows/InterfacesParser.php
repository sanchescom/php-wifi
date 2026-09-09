<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Windows;

use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Device;

/**
 * Parses `netsh wlan show interfaces`. When the interface is disconnected
 * (`State : disconnected`) it prints no `BSSID` or `SSID` line, so both stay
 * null.
 */
final class InterfacesParser
{
    private const FIELD = '/^\s*(Name|BSSID|SSID)\s*:\s*(.+?)\s*$/m';

    public function parse(string $output): InterfaceInfo
    {
        preg_match_all(self::FIELD, $output, $matches, PREG_SET_ORDER);

        $device = null;
        $bssid = null;
        $ssid = null;

        /** @var array<int, array{0: string, 1: string, 2: string}> $matches */
        foreach ($matches as [, $key, $value]) {
            match ($key) {
                'Name' => $device ??= new Device($value),
                'BSSID' => $bssid ??= Bssid::tryFrom($value),
                'SSID' => $ssid ??= $value,
                default => null,
            };
        }

        return new InterfaceInfo($device, $bssid, $ssid);
    }
}
