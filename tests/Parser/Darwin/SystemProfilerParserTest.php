<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Darwin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Darwin\SystemProfilerParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;

final class SystemProfilerParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/darwin';

    #[Test]
    public function it_parses_the_wifi_interface_only(): void
    {
        $networks = (new SystemProfilerParser())->parse(self::fixture('SystemProfiler.txt'));

        // one entry per network under en0 (current + others); nothing from awdl0
        $this->assertCount(6, $networks);
        $this->assertSame(
            ['AlphaNet-foiEmE', 'Offshore View Marine Services'],
            array_map(static fn (Network $network): string => $network->ssid, array_slice($networks, 0, 2)),
        );
    }

    #[Test]
    public function it_reads_channel_security_signal_and_omits_bssid(): void
    {
        $current = $this->findBySsid((new SystemProfilerParser())->parse(self::fixture('SystemProfiler.txt')), 'AlphaNet-foiEmE');

        $this->assertSame(157, $current->channel);
        $this->assertSame(Band::GHz5, $current->band);
        $this->assertSame(Security::WPA2, $current->security);
        $this->assertSame(-62.0, $current->signal?->dbm);
        $this->assertSame(76.0, $current->signal?->quality);
        $this->assertNull($current->bssid, 'system_profiler never reports a BSSID');
        $this->assertTrue($current->connected);
    }

    #[Test]
    public function only_the_current_network_is_connected(): void
    {
        $networks = (new SystemProfilerParser())->parse(self::fixture('SystemProfiler.txt'));

        $connected = array_values(array_filter($networks, static fn (Network $network): bool => $network->connected));

        $this->assertCount(1, $connected);
        $this->assertSame('AlphaNet-foiEmE', $connected[0]->ssid);
    }

    #[Test]
    public function redacted_networks_are_never_reported_as_connected(): void
    {
        $networks = (new SystemProfilerParser())->parse(self::fixture('SystemProfilerRedacted.txt'));

        $this->assertCount(6, $networks);

        foreach ($networks as $network) {
            $this->assertSame('<redacted>', $network->ssid);
            $this->assertTrue($network->ssidHidden);
            $this->assertFalse($network->connected);
        }
    }

    #[Test]
    public function it_ignores_networks_listed_under_other_interfaces(): void
    {
        $networks = (new SystemProfilerParser())->parse(self::fixture('SystemProfilerWithAwdl.txt'));

        $this->assertCount(6, $networks);
        $this->assertNotContains(
            'Peer-To-Peer-Ghost',
            array_map(static fn (Network $network): string => $network->ssid, $networks),
        );
    }

    #[Test]
    public function the_current_network_is_only_read_from_the_wifi_interface(): void
    {
        $networks = (new SystemProfilerParser())->parse(self::fixture('SystemProfilerAwdlCurrent.txt'));

        $connected = array_values(array_filter($networks, static fn (Network $network): bool => $network->connected));

        $this->assertSame([], $connected);
        $this->assertContains(
            'Peer-To-Peer-Ghost',
            array_map(static fn (Network $network): string => $network->ssid, $networks),
        );
    }

    #[Test]
    public function a_6ghz_network_gets_a_6ghz_frequency_and_band(): void
    {
        $network = $this->findBySsid((new SystemProfilerParser())->parse(self::fixture('SystemProfiler.txt')), 'FILUET-DSS');

        $this->assertSame(165, $network->channel);
        $this->assertSame(Band::GHz6, $network->band);
        $this->assertSame(6775, $network->frequency);
    }

    #[Test]
    public function a_network_without_a_signal_line_has_a_null_signal_not_a_sentinel(): void
    {
        $network = $this->findBySsid(
            (new SystemProfilerParser())->parse(self::fixture('SystemProfiler.txt')),
            'Offshore View Marine Services',
        );

        $this->assertNull($network->signal);
    }

    #[Test]
    public function looks_like_airport_detects_the_legacy_table_header(): void
    {
        $this->assertTrue(SystemProfilerParser::looksLikeAirport(self::fixture('Airport.txt')));
        $this->assertFalse(SystemProfilerParser::looksLikeAirport(self::fixture('SystemProfiler.txt')));
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    /** @param list<Network> $networks */
    private function findBySsid(array $networks, string $ssid): Network
    {
        foreach ($networks as $network) {
            if ($network->ssid === $ssid) {
                return $network;
            }
        }

        $this->fail(sprintf('No network with SSID "%s".', $ssid));
    }
}
