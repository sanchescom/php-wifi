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
            'LANG=C nmcli -w 10 device wifi connect "AlphaNet-foiEmE" password "123" ifname "someDevice"'
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
            'LANG=C nmcli device disconnect someDevice'
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
            'networksetup -setairportnetwork someDevice Offshore View Marine Services 123'
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
            'networksetup -removepreferredwirelessnetwork someDevice Offshore View Marine Services && '.
            'networksetup -setairportpower someDevice off && networksetup -setairportpower someDevice on'
        );
    }

    #[Test]
    #[Depends('it_should_return_networks_in_windows')]
    public function it_should_connect_in_windows(Collection $networks)
    {
        $network = $networks->firstOrFail();

        $network->connect('123', 'someDevice');

        $windowsPath = 'src'.DIRECTORY_SEPARATOR.'System'.DIRECTORY_SEPARATOR.'Windows';

        $this->assertEquals(
            $network->getCommand()->getLastCommand(),
            'netsh wlan add profile filename="'.str_replace('tests', $windowsPath, __DIR__).
            '/../../../tmp/AlphaNet-foiEmE.xml" && '.
            'netsh wlan connect interface="someDevice" ssid="AlphaNet-foiEmE" name="AlphaNet-foiEmE"'
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
