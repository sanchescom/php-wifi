<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exception;

final class NetworkNotFound extends WiFiException
{
    public static function bySsid(string $ssid): self
    {
        return new self(sprintf('No network named "%s" was found in the scan.', $ssid));
    }

    public static function byBssid(string $bssid): self
    {
        return new self(sprintf('No network with BSSID %s was found in the scan.', $bssid));
    }

    public static function none(): self
    {
        return new self('The scan contains no network with a signal reading.');
    }
}
