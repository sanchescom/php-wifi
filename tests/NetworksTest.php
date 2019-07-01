<?php

namespace Sanchescom\WiFi\Test\Windows;

use Sanchescom\WiFi\System\Collection;
use Sanchescom\WiFi\Test\BaseTestCase;
use Sanchescom\WiFi\Test\Windows\Mocks\NetworksCommand as WindowsNetworksCommand;
use Sanchescom\WiFi\Test\Mac\Mocks\NetworksCommand as MacNetworksCommand;
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

        $this->assertInstanceOf(Collection::class, $wifi::scan());
    }

    /**
     * @test
     */
    public function it_should_return_networks_in_mac()
    {
        $wifi = new WiFi();
        $wifi::setCommandClass(MacNetworksCommand::class);

        $this->assertInstanceOf(Collection::class, $wifi::scan());
    }
}
