<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Value;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\NetworkCollection;
use Sanchescom\WiFi\Value\Security;
use Sanchescom\WiFi\Value\Signal;

final class NetworkCollectionTest extends TestCase
{
    private static function net(
        string $ssid,
        ?float $dbm,
        ?int $freq = 2412,
        bool $hidden = false,
        ?string $bssid = null,
        bool $connected = false,
    ): Network {
        return new Network(
            ssid: $ssid,
            ssidHidden: $hidden,
            bssid: $bssid !== null ? Bssid::from($bssid) : null,
            channel: null,
            band: $freq !== null ? Band::fromFrequency($freq) : null,
            frequency: $freq,
            signal: $dbm !== null ? Signal::fromDbm($dbm) : null,
            security: Security::Open,
            securityFlags: '',
            connected: $connected,
        );
    }

    #[Test]
    public function by_ssid_returns_the_matching_network(): void
    {
        $collection = new NetworkCollection([self::net('Home', -50.0), self::net('Cafe', -70.0)]);

        $this->assertSame('Cafe', $collection->bySsid('Cafe')->ssid);
    }

    #[Test]
    public function by_ssid_throws_network_not_found_when_missing(): void
    {
        $collection = new NetworkCollection([self::net('Home', -50.0)]);

        $this->expectException(NetworkNotFound::class);
        $collection->bySsid('Missing');
    }

    #[Test]
    public function by_bssid_accepts_a_string_or_a_bssid_value_object(): void
    {
        $network = self::net('Home', -50.0, bssid: '04:8d:38:22:78:9e');
        $collection = new NetworkCollection([$network]);

        $this->assertSame($network, $collection->byBssid('04:8d:38:22:78:9e'));
        $this->assertSame($network, $collection->byBssid(Bssid::from('04:8D:38:22:78:9E')));
    }

    #[Test]
    public function by_bssid_throws_network_not_found_when_missing(): void
    {
        $collection = new NetworkCollection([self::net('Home', -50.0, bssid: '04:8d:38:22:78:9e')]);

        $this->expectException(NetworkNotFound::class);
        $collection->byBssid('aa:bb:cc:dd:ee:ff');
    }

    #[Test]
    public function band_filters_by_frequency(): void
    {
        $twoFour = self::net('A', -50.0, freq: 2412);
        $five = self::net('B', -50.0, freq: 5500);
        $collection = new NetworkCollection([$twoFour, $five]);

        $filtered = $collection->band(Band::GHz5);

        $this->assertSame(['B'], $filtered->pluck('ssid')->all());
    }

    #[Test]
    public function strongest_ignores_null_signals(): void
    {
        $weak = self::net('A', -70.0);
        $strong = self::net('B', -50.0);
        $none = self::net('C', null);
        $collection = new NetworkCollection([$weak, $strong, $none]);

        $this->assertSame($strong, $collection->strongest());
    }

    #[Test]
    public function strongest_throws_when_every_signal_is_null(): void
    {
        $collection = new NetworkCollection([self::net('A', null), self::net('B', null)]);

        $this->expectException(NetworkNotFound::class);
        $collection->strongest();
    }

    #[Test]
    public function stronger_than_filters_by_threshold(): void
    {
        $strong = self::net('A', -50.0);
        $weak = self::net('B', -70.0);
        $collection = new NetworkCollection([$strong, $weak]);

        $filtered = $collection->strongerThan(-60.0);

        $this->assertSame(['A'], $filtered->pluck('ssid')->all());
    }

    #[Test]
    public function sort_by_signal_puts_null_signals_last(): void
    {
        $weak = self::net('A', -70.0);
        $none = self::net('B', null);
        $strong = self::net('C', -50.0);
        $collection = new NetworkCollection([$weak, $none, $strong]);

        $sorted = $collection->sortBySignal();

        $this->assertSame(['C', 'A', 'B'], $sorted->pluck('ssid')->all());
    }

    #[Test]
    public function unique_by_ssid_keeps_the_strongest_per_ssid_in_first_appearance_order(): void
    {
        $weakFirst = self::net('Home', -70.0, bssid: '04:8d:38:22:78:9e');
        $strongerSecond = self::net('Home', -50.0, bssid: '04:8d:38:22:78:9f');
        $other = self::net('Cafe', -60.0, bssid: '04:8d:38:22:78:aa');
        $collection = new NetworkCollection([$weakFirst, $other, $strongerSecond]);

        $unique = $collection->uniqueBySsid();

        $this->assertSame(['Home', 'Cafe'], $unique->pluck('ssid')->all());
        $this->assertSame('04:8d:38:22:78:9f', (string) $unique->bySsid('Home')->bssid);
    }

    #[Test]
    public function unique_by_ssid_never_merges_empty_or_hidden_ssids(): void
    {
        $empty1 = self::net('', -70.0, bssid: '04:8d:38:22:78:9e');
        $empty2 = self::net('', -50.0, bssid: '04:8d:38:22:78:9f');
        $hidden1 = self::net('Redacted', -70.0, hidden: true, bssid: '04:8d:38:22:78:aa');
        $hidden2 = self::net('Redacted', -50.0, hidden: true, bssid: '04:8d:38:22:78:ab');
        $named = self::net('Cafe', -60.0, bssid: '04:8d:38:22:78:ac');
        $collection = new NetworkCollection([$empty1, $named, $hidden1, $empty2, $hidden2]);

        $unique = $collection->uniqueBySsid();

        $this->assertSame(
            5,
            $unique->count(),
            'the named network is deduplicated, but every empty-SSID and hidden network is kept as its own entry',
        );
        $this->assertSame(['Cafe', '', 'Redacted', '', 'Redacted'], $unique->pluck('ssid')->all());
    }

    #[Test]
    public function has_hidden_ssids_reflects_whether_any_network_is_hidden(): void
    {
        $this->assertTrue((new NetworkCollection([self::net('A', -50.0, hidden: true)]))->hasHiddenSsids());
        $this->assertFalse((new NetworkCollection([self::net('A', -50.0)]))->hasHiddenSsids());
    }

    #[Test]
    public function connected_returns_only_connected_networks(): void
    {
        $connected = self::net('A', -50.0, connected: true);
        $disconnected = self::net('B', -50.0, connected: false);
        $collection = new NetworkCollection([$connected, $disconnected]);

        $this->assertSame(['A'], $collection->connected()->pluck('ssid')->all());
    }
}
