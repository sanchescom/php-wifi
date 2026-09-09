<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

final readonly class Hotspot
{
    public function __construct(
        public string $connectionName,
        public string $ssid,
        public Device $device,
    ) {
    }
}
