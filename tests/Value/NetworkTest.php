<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Value;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;
use Sanchescom\WiFi\Value\Signal;

final class NetworkTest extends TestCase
{
    private static function network(bool $connected): Network
    {
        return new Network(
            ssid: 'Cafe Corner',
            ssidHidden: false,
            bssid: Bssid::from('04:8d:38:22:78:9e'),
            channel: 7,
            band: Band::GHz2_4,
            frequency: 2442,
            signal: Signal::fromDbm(-50.0),
            security: Security::WPA2,
            securityFlags: 'CCMP',
            connected: $connected,
        );
    }

    #[Test]
    public function with_connected_returns_a_clone_with_only_connected_changed(): void
    {
        $original = self::network(false);

        $marked = $original->withConnected(true);

        $this->assertNotSame($original, $marked);
        $this->assertTrue($marked->connected);
        $this->assertSame($original->ssid, $marked->ssid);
        $this->assertSame($original->ssidHidden, $marked->ssidHidden);
        $this->assertSame($original->bssid, $marked->bssid);
        $this->assertSame($original->channel, $marked->channel);
        $this->assertSame($original->band, $marked->band);
        $this->assertSame($original->frequency, $marked->frequency);
        $this->assertSame($original->signal, $marked->signal);
        $this->assertSame($original->security, $marked->security);
        $this->assertSame($original->securityFlags, $marked->securityFlags);
    }

    #[Test]
    public function with_connected_leaves_the_original_network_unchanged(): void
    {
        $original = self::network(false);

        $original->withConnected(true);

        $this->assertFalse($original->connected);
    }

    #[Test]
    public function with_connected_can_also_unmark_a_connected_network(): void
    {
        $original = self::network(true);

        $unmarked = $original->withConnected(false);

        $this->assertFalse($unmarked->connected);
        $this->assertTrue($original->connected);
    }
}
