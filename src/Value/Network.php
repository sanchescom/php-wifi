<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

final readonly class Network
{
    public function __construct(
        public string $ssid,
        public bool $ssidHidden,
        public ?Bssid $bssid,
        public ?int $channel,
        public ?Band $band,
        public ?int $frequency,
        public ?Signal $signal,
        public Security $security,
        public string $securityFlags,
        public bool $connected,
    ) {
    }
}
