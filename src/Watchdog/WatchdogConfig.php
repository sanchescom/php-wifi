<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Watchdog;

use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Value\HotspotConfig;

/**
 * Tuning for {@see Watchdog}.
 *
 * $ssid is the network to keep rejoining; null means "any known network",
 * tried in the order {@see \Sanchescom\WiFi\WiFi::knownNetworks()} returns
 * them. $hotspot is raised when nothing can be joined. $interval is the gap
 * between ticks in run(); $retryAfter is how long an idle, unattended
 * hotspot is left standing before the Watchdog tears it down to give the
 * network another try. $requireIdleHotspot, when true (the default), never
 * lets the Watchdog touch a hotspot that has an attached station — someone
 * is probably typing a passphrase on it.
 */
final readonly class WatchdogConfig
{
    public function __construct(
        public ?string $ssid,
        public HotspotConfig $hotspot,
        public int $interval = 30,
        public int $retryAfter = 300,
        public bool $requireIdleHotspot = true,
    ) {
        if ($interval <= 0) {
            throw new InvalidArgument('interval must be a positive number of seconds.');
        }

        if ($retryAfter <= 0) {
            throw new InvalidArgument('retryAfter must be a positive number of seconds.');
        }
    }
}
