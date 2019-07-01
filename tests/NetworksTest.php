<?php

namespace Sanchescom\WiFi\Test;

use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\Collection;
use Sanchescom\WiFi\Test\Mac\Mocks\NetworksCommand as MacNetworksCommand;
use Sanchescom\WiFi\Test\Windows\Mocks\NetworksCommand as WindowsNetworksCommand;
use Sanchescom\WiFi\WiFi;

class NetworksTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @test
     */
    public function it_should_return_networks_in_windows()
    {
        $wifi = new WiFi();
        $wifi::setCommandClass(WindowsNetworksCommand::class);
        $wifi::setPhpOperationSystem('WINNT');
        $this->assets($wifi::scan());
    }

    /**
     * @test
     */
    public function it_should_return_networks_in_mac()
    {
        $wifi = new WiFi();
        $wifi::setCommandClass(MacNetworksCommand::class);
        $wifi::setPhpOperationSystem('DARWIN');
        $this->assets($wifi::scan());
    }

    /**
     * @param $networks
     */
    protected function assets($networks)
    {
        $this->assertInstanceOf(Collection::class, $networks);
        $this->assertContainsOnlyInstancesOf(AbstractNetwork::class, $networks);
    }
}
