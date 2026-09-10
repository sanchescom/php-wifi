<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

use Sanchescom\WiFi\Exception\InvalidArgument;

final readonly class HotspotConfig
{
    public function __construct(
        public string $ssid,
        public string $password,
        public ?Band $band = null,
        public ?Device $device = null,
    ) {
        if ($ssid === '' || strlen($ssid) > 32) {
            throw new InvalidArgument('A hotspot SSID must be 1–32 bytes long.');
        }
        if (strlen($password) < 8 || strlen($password) > 63) {
            throw new InvalidArgument('A WPA2 hotspot passphrase must be 8–63 characters long.');
        }
    }
}
