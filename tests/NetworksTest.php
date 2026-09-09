<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\Exceptions\NetworkNotFoundException;
use Sanchescom\WiFi\Exceptions\UnknownSystemException;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\Collection;
use Sanchescom\WiFi\Test\Darwin\Mocks\NetworksCommand as DarwinNetworksCommand;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksCommand as LinuxNetworksCommand;
use Sanchescom\WiFi\Test\Windows\Mocks\NetworksCommand as WindowsNetworksCommand;
use Sanchescom\WiFi\WiFi;

class NetworksTest extends BaseTestCase
{
    /** @var string */
    const BSSID = '04:8d:38:22:78:9e';

    /** @var string */
    const SSID = 'AlphaNet-foiEmE';

    /** {@inheritdoc} */
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_should_return_networks_in_windows()
    {
        $wifi = new WiFi();
        $wifi::setCommandClass(WindowsNetworksCommand::class);
        $wifi::setPhpOperationSystem('Windows');

        $networks = $wifi::scan();

        $this->assetsContains($networks);
        $this->assetsCollections($networks);

        return $networks;
    }

    #[Test]
    public function it_should_return_networks_in_darwin()
    {
        $wifi = new WiFi();
        $wifi::setCommandClass(DarwinNetworksCommand::class);
        $wifi::setPhpOperationSystem('Darwin');

        $networks = $wifi::scan();

        $this->assetsContains($networks);
        $this->assetsCollections($networks);

        return $networks;
    }

    #[Test]
    public function it_should_return_networks_in_linux()
    {
        $wifi = new WiFi();
        $wifi::setCommandClass(LinuxNetworksCommand::class);
        $wifi::setPhpOperationSystem('Linux');

        $networks = $wifi::scan();

        $this->assetsContains($networks);
        $this->assetsCollections($networks);

        return $networks;
    }

    #[Test]
    #[Depends('it_should_return_networks_in_linux')]
    public function it_should_report_linux_signal_in_both_units(Collection $networks): void
    {
        $network = $networks->getBySsid(self::SSID);

        $this->assertSame(72.0, $network->quality);
        $this->assertSame(-64.0, $network->dbm);
    }

    #[Test]
    #[Depends('it_should_return_networks_in_linux')]
    public function it_should_filter_bands_by_frequency(Collection $networks): void
    {
        $this->assertSame(6, $networks->get24GhzNetworks()->count());
        $this->assertSame(0, $networks->get5GhzNetworks()->count());
        $this->assertSame(0, $networks->get6GhzNetworks()->count());
    }

    #[Test]
    #[Depends('it_should_return_networks_in_windows')]
    public function it_should_report_windows_signal_in_both_units(Collection $networks): void
    {
        $network = $networks->getBySsid(self::SSID);

        $this->assertSame(99.0, $network->quality);
        $this->assertSame(-50.5, $network->dbm);
    }

    #[Test]
    #[Depends('it_should_return_networks_in_darwin')]
    public function it_should_report_darwin_signal_in_both_units(Collection $networks): void
    {
        $network = $networks->getBySsid(self::SSID);

        $this->assertSame(-83.0, $network->dbm);
        $this->assertSame(34.0, $network->quality);
    }

    #[Test]
    public function it_should_throw_exception_if_unknown_os()
    {
        $this->expectException(UnknownSystemException::class);

        $wifi = new WiFi();
        $wifi::setPhpOperationSystem('Unknown');

        $this->assetsContains($wifi::scan());
    }

    protected function assetsContains($networks)
    {
        $this->assertInstanceOf(Collection::class, $networks);
        $this->assertContainsOnlyInstancesOf(AbstractNetwork::class, $networks);
    }

    protected function assetsCollections(Collection $networks)
    {
        $this->assertIsArray($networks->getAll());
        $this->assertInstanceOf(AbstractNetwork::class, $networks->getAll()[0]);

        $this->assertIsArray($networks->getConnected());
        $this->assertTrue(array_values($networks->getConnected())[0]->connected);

        $ssid = $networks->getBySsid(self::SSID)->ssid;

        try {
            $network = $networks->getByBssid(strtolower(self::BSSID));
        } catch (NetworkNotFoundException $exception) {
            $network = $networks->getByBssid(strtoupper(self::BSSID));
        }

        $this->assertEquals($ssid, self::SSID);
        $this->assertEquals(strtolower($network->bssid), strtolower(self::BSSID));
        $this->assertIsString((string) $network);

        try {
            $this->assertEquals($networks->getBySsid('123')->ssid, self::SSID);
        } catch (NetworkNotFoundException $exception) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    #[Depends('it_should_return_networks_in_linux')]
    public function it_should_connect_in_linux(Collection $networks)
    {
        $network = $networks->firstOrFail();

        $network->connect('123', 'someDevice');

        $this->assertEquals(
            $network->getCommand()->getLastCommand(),
            'LANG=C nmcli -w 10 device wifi connect '.escapeshellarg('AlphaNet-foiEmE')
            .' password '.escapeshellarg('123').' ifname '.escapeshellarg('someDevice')
        );
    }

    #[Test]
    #[Depends('it_should_return_networks_in_linux')]
    public function it_should_disconnect_in_linux(Collection $networks)
    {
        $network = $networks->firstOrFail();

        $network->disconnect('someDevice');

        $this->assertEquals(
            $network->getCommand()->getLastCommand(),
            'LANG=C nmcli device disconnect '.escapeshellarg('someDevice')
        );
    }

    #[Test]
    #[Depends('it_should_return_networks_in_darwin')]
    public function it_should_connect_in_darwin(Collection $networks)
    {
        $network = $networks->firstOrFail();

        $network->connect('123', 'someDevice');

        $this->assertEquals(
            $network->getCommand()->getLastCommand(),
            'networksetup -setairportnetwork '.escapeshellarg('someDevice').' '
            .escapeshellarg('Offshore View Marine Services').' '.escapeshellarg('123')
        );
    }

    #[Test]
    #[Depends('it_should_return_networks_in_darwin')]
    public function it_should_disconnect_in_darwin(Collection $networks)
    {
        $network = $networks->firstOrFail();

        $network->disconnect('someDevice');

        $this->assertEquals(
            $network->getCommand()->getLastCommand(),
            'networksetup -removepreferredwirelessnetwork '.escapeshellarg('someDevice').' '
            .escapeshellarg('Offshore View Marine Services')
            .' && networksetup -setairportpower '.escapeshellarg('someDevice').' off'
            .' && networksetup -setairportpower '.escapeshellarg('someDevice').' on'
        );
    }

    #[Test]
    #[Depends('it_should_return_networks_in_windows')]
    public function it_should_connect_in_windows(Collection $networks)
    {
        $network = $networks->firstOrFail();

        $network->connect('123', 'someDevice');

        // "(?:/private)?": on macOS tempnam() may return the /private/var form of a /var temp dir
        $this->assertMatchesRegularExpression(
            '#^netsh wlan add profile filename="(?:/private)?' . preg_quote(sys_get_temp_dir(), '#') . '/php-wifi-[^"]+" && '
            . 'netsh wlan connect interface="someDevice" ssid="AlphaNet-foiEmE" name="AlphaNet-foiEmE"$#',
            $network->getCommand()->getLastCommand()
        );
    }

    #[Test]
    #[Depends('it_should_return_networks_in_windows')]
    public function it_should_disconnect_in_windows(Collection $networks)
    {
        $network = $networks->firstOrFail();

        $network->disconnect('someDevice');

        $this->assertEquals(
            $network->getCommand()->getLastCommand(),
            'netsh wlan disconnect interface="someDevice"'
        );
    }
}
