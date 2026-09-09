<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Nmcli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Nmcli\ListParser;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;

final class ListParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/linux';

    #[Test]
    public function it_parses_the_networks_fixture_into_six_networks(): void
    {
        $networks = (new ListParser())->parse((string) file_get_contents(self::FIXTURES . '/Networks.txt'));

        $this->assertCount(6, $networks);

        $alpha = $this->findBySsid($networks, 'AlphaNet-foiEmE');

        $this->assertTrue($alpha->connected);
        $this->assertSame('04:8d:38:22:78:9e', (string) $alpha->bssid);
        $this->assertSame(7, $alpha->channel);
        $this->assertSame(2442, $alpha->frequency);
        $this->assertSame(Band::GHz2_4, $alpha->band);
        $this->assertSame(72.0, $alpha->signal?->quality);
        $this->assertSame(-64.0, $alpha->signal?->dbm);
        $this->assertSame(Security::WPA2, $alpha->security);
        $this->assertSame(
            'pair_tkip pair_ccmp group_tkip psk pair_tkip pair_ccmp group_tkip psk',
            $alpha->securityFlags,
        );
    }

    #[Test]
    public function it_does_not_shift_fields_on_an_escaped_colon_in_the_ssid(): void
    {
        $networks = (new ListParser())->parse((string) file_get_contents(self::FIXTURES . '/NetworksColon.txt'));

        $cafe = $this->findBySsid($networks, 'Cafe: Corner');
        $this->assertSame('70:9f:2d:97:f7:f7', (string) $cafe->bssid);

        $hidden = $this->findByBssid($networks, '2c:99:24:3c:a2:f9');
        $this->assertSame('', $hidden->ssid);
        $this->assertTrue($hidden->ssidHidden);
    }

    #[Test]
    public function it_parses_the_pi_fixture_with_a_duplicated_ssid(): void
    {
        $networks = (new ListParser())->parse((string) file_get_contents(self::FIXTURES . '/NetworksPi.txt'));

        $this->assertCount(5, $networks);
        $bell = array_values(array_filter($networks, static fn (Network $network): bool => $network->ssid === 'BELL340'));
        $this->assertCount(2, $bell);
    }

    #[Test]
    public function it_drops_malformed_lines_and_handles_unusable_signal_and_open_security(): void
    {
        $output = <<<'TXT'
        # SYNTHETIC: edge cases
        no:OpenNet:11\:22\:33\:44\:55\:66:Infra:6:2437 MHz:50:--:(none):(none)
        no:WeirdSignal:11\:22\:33\:44\:55\:67:Infra:6:2437 MHz:(none):WPA2:(none):pair_ccmp group_ccmp psk
        no:Incomplete:11\:22\:33\:44\:55\:68:Infra:6:2437 MHz:50:WPA2:(none)
        TXT;

        $networks = (new ListParser())->parse($output);

        $this->assertCount(2, $networks);

        $open = $this->findBySsid($networks, 'OpenNet');
        $this->assertSame(Security::Open, $open->security);

        $weird = $this->findBySsid($networks, 'WeirdSignal');
        $this->assertNull($weird->signal);
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
