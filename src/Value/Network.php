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

    /** A clone with only `connected` changed; every other field is copied as-is. */
    public function withConnected(bool $connected): self
    {
        return new self(
            ssid: $this->ssid,
            ssidHidden: $this->ssidHidden,
            bssid: $this->bssid,
            channel: $this->channel,
            band: $this->band,
            frequency: $this->frequency,
            signal: $this->signal,
            security: $this->security,
            securityFlags: $this->securityFlags,
            connected: $connected,
        );
    }
}
