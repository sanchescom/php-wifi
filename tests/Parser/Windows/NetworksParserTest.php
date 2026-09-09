<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Windows;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Windows\NetworksParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;

final class NetworksParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/windows';

    #[Test]
    public function it_parses_networks_txt_into_six_networks(): void
    {
        $networks = (new NetworksParser())->parse(self::fixture('Networks.txt'));

        $this->assertCount(6, $networks);
    }

    #[Test]
    public function it_reads_bssid_signal_channel_and_security_of_alphanet(): void
    {
        $network = $this->findBySsid((new NetworksParser())->parse(self::fixture('Networks.txt')), 'AlphaNet-foiEmE');

        $this->assertSame('04:8d:38:22:78:9e', (string) $network->bssid);
        $this->assertSame(99.0, $network->signal?->quality);
        $this->assertSame(7, $network->channel);
        $this->assertSame(Security::WPA2, $network->security);
        $this->assertSame('CCMP', $network->securityFlags);
    }

    #[Test]
    public function a_channel_at_or_below_fourteen_is_the_2_4ghz_band_with_its_frequency(): void
    {
        $network = $this->findBySsid((new NetworksParser())->parse(self::fixture('Networks.txt')), 'AlphaNet-foiEmE');

        $this->assertSame(Band::GHz2_4, $network->band);
        $this->assertSame(2442, $network->frequency);
    }

    #[Test]
    public function the_networks_output_alone_never_reports_a_connected_network(): void
    {
        $networks = (new NetworksParser())->parse(self::fixture('Networks.txt'));

        foreach ($networks as $network) {
            $this->assertFalse($network->connected);
        }
    }

    #[Test]
    public function it_tolerates_crlf_line_endings(): void
    {
        $crlf = str_replace("\n", "\r\n", self::fixture('Networks.txt'));

        $networks = (new NetworksParser())->parse($crlf);

        $this->assertCount(6, $networks);
        $this->assertSame('04:8d:38:22:78:9e', (string) $this->findBySsid($networks, 'AlphaNet-foiEmE')->bssid);
    }

    #[Test]
    public function a_block_with_no_signal_line_does_not_crash_and_has_a_null_signal(): void
    {
        $block = <<<'TXT'
            SSID 1 : NoSignalNetwork
                Network type            : Infrastructure
                Authentication          : WPA2-Personal
                Encryption              : CCMP
                BSSID 1                 : 04:8d:38:22:78:9e
                     Radio type         : 802.11n
                     Channel            : 7
                     Basic rates (Mbps) : 6.5 16 19.5 117
                     Other rates (Mbps) : 18 19.5 24 36 39 48 54 156

            TXT;

        $networks = (new NetworksParser())->parse($block);

        $this->assertCount(1, $networks);
        $this->assertNull($networks[0]->signal);
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
