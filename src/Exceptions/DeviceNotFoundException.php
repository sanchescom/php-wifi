<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exceptions;

use RuntimeException;

class DeviceNotFoundException extends RuntimeException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('No Wi-Fi device found; pass the device name explicitly. ' . $detail));
    }
}
