<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exception;

final class DeviceNotFound extends WiFiException
{
    public static function onThisSystem(string $detail): self
    {
        return new self(sprintf('No Wi-Fi device was found on this system: %s', $detail));
    }
}
