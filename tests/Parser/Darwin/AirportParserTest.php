<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Darwin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Darwin\AirportParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Network;

final class AirportParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/darwin';

    #[Test]
    public function it_parses_the_scan_table_into_four_networks(): void
    {
        $networks = (new AirportParser())->parse(self::scanTable());

        $this->assertCount(4, $networks);
    }

    #[Test]
    public function it_reads_bssid_signal_and_channel_and_derives_the_5ghz_frequency(): void
    {
        $network = $this->findBySsid((new AirportParser())->parse(self::scanTable()), 'AlphaNet-foiEmE');

        $this->assertSame('04:8d:38:22:78:9e', (string) $network->bssid);
        $this->assertSame(-83.0, $network->signal?->dbm);
        $this->assertSame(100, $network->channel);
        $this->assertSame(Band::GHz5, $network->band);
        $this->assertSame(5500, $network->frequency);
    }

    #[Test]
    public function the_table_alone_never_reports_a_connected_network(): void
    {
        $networks = (new AirportParser())->parse(self::scanTable());

        foreach ($networks as $network) {
            $this->assertFalse($network->connected);
        }
    }

    #[Test]
    public function a_channel_at_or_below_fourteen_is_the_2_4ghz_band(): void
    {
        $network = $this->findBySsid((new AirportParser())->parse(self::scanTable()), 'Offshore View Marine Services');

        $this->assertSame(6, $network->channel);
        $this->assertSame(Band::GHz2_4, $network->band);
    }

    /** The scan table only — the part of the airport fixture before the `--separator--` current-network dump. */
    private static function scanTable(): string
    {
        $fixture = (string) file_get_contents(self::FIXTURES . '/Airport.txt');

        return explode('--separator--', $fixture)[0];
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
