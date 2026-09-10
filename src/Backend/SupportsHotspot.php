<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\Hotspot;
use Sanchescom\WiFi\Value\HotspotConfig;

interface SupportsHotspot
{
    /** @throws UnsupportedOperation when the requested band is not supported */
    public function startHotspot(HotspotConfig $config, Device $device): Hotspot;

    public function stopHotspot(): void;

    public function isHotspotActive(): bool;
}
