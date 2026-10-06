<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

use Sanchescom\WiFi\Exception\InvalidArgument;

/**
 * $channel and $country are optional: without them a backend keeps its own
 * choice of channel and the regulatory domain the system already has. A
 * channel has to exist on $band; given without a band, it has to exist on
 * 2.4 or 5 GHz, and {@see self::resolvedBand()} tells which. $country is an
 * ISO 3166-1 alpha-2 code in upper case ("DE", "US").
 */
final readonly class HotspotConfig
{
    public function __construct(
        public string $ssid,
        public string $password,
        public ?Band $band = null,
        public ?Device $device = null,
        public ?int $channel = null,
        public ?string $country = null,
    ) {
        if ($ssid === '' || strlen($ssid) > 32) {
            throw new InvalidArgument('A hotspot SSID must be 1–32 bytes long.');
        }
        if (strlen($password) < 8 || strlen($password) > 63) {
            throw new InvalidArgument('A WPA2 hotspot passphrase must be 8–63 characters long.');
        }
        if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgument('A hotspot country must be a two-letter code in upper case, such as "DE".');
        }
        if ($channel !== null && $this->resolvedBand() === null) {
            throw new InvalidArgument(sprintf(
                'Channel %d does not exist on %s.',
                $channel,
                $band !== null ? 'the ' . $band->value . ' GHz band' : 'the 2.4 or the 5 GHz band',
            ));
        }
    }

    /** The band to use: the one given, else the one $channel belongs to, else null (the backend's default). */
    public function resolvedBand(): ?Band
    {
        if ($this->channel === null) {
            return $this->band;
        }

        foreach ($this->band !== null ? [$this->band] : [Band::GHz2_4, Band::GHz5] as $band) {
            if ($band->frequencyForChannel($this->channel) !== null) {
                return $band;
            }
        }

        return null;
    }
}
