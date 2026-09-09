<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Windows;

use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Device;

final readonly class InterfaceInfo
{
    public function __construct(
        public ?Device $device,
        public ?Bssid $connectedBssid,
        public ?string $connectedSsid,
    ) {
    }
}
