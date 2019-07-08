<?php

namespace Sanchescom\WiFi\Test;

use Sanchescom\WiFi\Exceptions\UnknownSystemException;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\Collection;
use Sanchescom\WiFi\Test\Darwin\Mocks\NetworksCommand as DarwinNetworksCommand;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksCommand as LinuxNetworksCommand;
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
        $wifi::setPhpOperationSystem('Windows');
        $this->assets($wifi::scan());
    }

    /**
     * @test
     */
    public function it_should_return_networks_in_darwin()
    {
        $wifi = new WiFi();
        $wifi::setCommandClass(DarwinNetworksCommand::class);
        $wifi::setPhpOperationSystem('Darwin');
        $this->assets($wifi::scan());
    }

    /**
     * @test
     */
    public function it_should_return_networks_in_linux()
    {
        $wifi = new WiFi();
        $wifi::setCommandClass(LinuxNetworksCommand::class);
        $wifi::setPhpOperationSystem('Linux');
        $this->assets($wifi::scan());
    }

    /**
     * @test
     */
    public function it_should_throw_exception_if_unknown_os()
    {
        $this->expectException(UnknownSystemException::class);

        $wifi = new WiFi();
        $wifi::setPhpOperationSystem('Unknown');
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
