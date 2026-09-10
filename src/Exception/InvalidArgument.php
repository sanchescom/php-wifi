<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exception;

use Sanchescom\WiFi\Value\Network;

final class InvalidArgument extends WiFiException
{
    public static function hiddenNetwork(Network $network): self
    {
        return new self(
            'Cannot connect to a network whose SSID is hidden or redacted; pass the SSID as a string instead.',
        );
    }

    public static function emptySsid(): self
    {
        return new self('SSID must not be empty.');
    }
}
