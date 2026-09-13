<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\WpaCli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\WpaCli\ScanResultsParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;

final class ScanResultsParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/wpacli';

    #[Test]
    public function it_parses_four_networks_from_the_fixture(): void
    {
        $networks = (new ScanResultsParser())->parse((string) file_get_contents(self::FIXTURES . '/ScanResults.txt'));

        $this->assertCount(4, $networks);

        $bell = $this->findBySsid($networks, 'BELL340');
        $this->assertSame('0e:ac:8a:99:58:5c', (string) $bell->bssid);
        $this->assertSame(2412, $bell->frequency);
        $this->assertSame(Band::GHz2_4, $bell->band);
        $this->assertSame(1, $bell->channel);
        $this->assertSame(-50.0, $bell->signal?->dbm);
        $this->assertSame(Security::WPA2, $bell->security);
        $this->assertSame('[WPA2-PSK-CCMP][ESS]', $bell->securityFlags);
        $this->assertFalse($bell->ssidHidden);
    }

    #[Test]
    public function wpa2_ccmp_plus_tkip_and_ess_is_wpa2_security(): void
    {
        $networks = (new ScanResultsParser())->parse((string) file_get_contents(self::FIXTURES . '/ScanResults.txt'));

        $vtech = $this->findBySsid($networks, 'VTECH_5764_9764');

        $this->assertSame(Security::WPA2, $vtech->security);
        $this->assertSame('[WPA2-PSK-CCMP+TKIP][ESS]', $vtech->securityFlags);
    }

    #[Test]
    public function wps_alongside_wpa2_is_still_classified_as_wpa2_with_the_raw_flags_preserved(): void
    {
        $output = <<<'TXT'
        # SYNTHETIC: wpa_cli -i wlan0 scan_results
        bssid / frequency / signal level / flags / ssid
        12:34:56:78:9a:be	5200	-60	[WPA2-PSK-CCMP][WPS][ESS]	WPSHome
        TXT;

        $networks = (new ScanResultsParser())->parse($output);

        $wps = $this->findBySsid($networks, 'WPSHome');

        $this->assertSame(Security::WPA2, $wps->security);
        $this->assertSame('[WPA2-PSK-CCMP][WPS][ESS]', $wps->securityFlags);
    }

    #[Test]
    public function an_ssid_escaped_as_xnn_bytes_that_reassemble_a_multi_byte_utf8_character_is_decoded(): void
    {
        $networks = (new ScanResultsParser())->parse(
            (string) file_get_contents(self::FIXTURES . '/ScanResultsEscaped.txt'),
        );

        $cyrillic = $this->findByBssid($networks, '12:34:56:78:9a:bc');

        $this->assertSame('Привет', $cyrillic->ssid);
    }

    #[Test]
    public function an_ssid_escaped_as_a_single_invalid_utf8_byte_survives_unchanged(): void
    {
        $networks = (new ScanResultsParser())->parse(
            (string) file_get_contents(self::FIXTURES . '/ScanResultsEscaped.txt'),
        );

        $invalid = $this->findByBssid($networks, '12:34:56:78:9a:bd');

        $this->assertSame("\xff", $invalid->ssid);
    }

    #[Test]
    public function a_row_with_an_empty_ssid_field_is_a_hidden_network(): void
    {
        $networks = (new ScanResultsParser())->parse((string) file_get_contents(self::FIXTURES . '/ScanResults.txt'));

        $hidden = $this->findByBssid($networks, '0e:ac:8a:99:58:5e');

        $this->assertSame('', $hidden->ssid);
        $this->assertTrue($hidden->ssidHidden);
    }

    #[Test]
    public function ess_alone_without_wpa_or_wep_is_open_security(): void
    {
        $networks = (new ScanResultsParser())->parse((string) file_get_contents(self::FIXTURES . '/ScanResults.txt'));

        $open = $this->findBySsid($networks, 'OpenCafe');

        $this->assertSame(Security::Open, $open->security);
        $this->assertSame('[ESS]', $open->securityFlags);
    }

    #[Test]
    public function the_header_line_and_the_synthetic_marker_are_skipped(): void
    {
        $networks = (new ScanResultsParser())->parse((string) file_get_contents(self::FIXTURES . '/ScanResults.txt'));

        foreach ($networks as $network) {
            $this->assertNotSame('bssid', $network->ssid);
        }
    }

    #[Test]
    public function a_row_with_fewer_than_five_fields_is_dropped(): void
    {
        $output = <<<'TXT'
        # SYNTHETIC: wpa_cli -i wlan0 scan_results
        bssid / frequency / signal level / flags / ssid
        0e:ac:8a:99:58:5c	2412	-50	[WPA2-PSK-CCMP][ESS]	BELL340
        0e:ac:8a:99:58:5f	2412	-50
        TXT;

        $networks = (new ScanResultsParser())->parse($output);

        $this->assertCount(1, $networks);
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

    /** @param list<Network> $networks */
    private function findByBssid(array $networks, string $bssid): Network
    {
        foreach ($networks as $network) {
            if ($network->bssid !== null && $network->bssid->equals($bssid)) {
                return $network;
            }
        }

        $this->fail(sprintf('No network with BSSID "%s".', $bssid));
    }
}
