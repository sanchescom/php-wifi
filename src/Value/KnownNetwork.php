<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

final readonly class KnownNetwork
{
    public function __construct(
        public string $name,
        public string $ssid,
        public ?Device $device,
        public bool $active,
    ) {
    }
}
