<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\NetworkCollection;

interface Backend
{
    public function scan(): NetworkCollection;

    public function connect(string $ssid, Credentials $credentials, Device $device): void;

    public function disconnect(Device $device): void;

    /** @throws DeviceNotFound when no Wi-Fi device is found on this system */
    public function detectDevice(): Device;
}
