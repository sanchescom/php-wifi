<?php

namespace Sanchescom\WiFi\Test\Windows;

use Sanchescom\WiFi\System\Collection;
use Sanchescom\WiFi\Test\BaseTestCase;
use Sanchescom\WiFi\Test\Windows\Mocks\NetworksCommand;
use Sanchescom\WiFi\WiFi;

class NetworksTest extends BaseTestCase
{
    /**
     * @var WiFi
     */
    protected $wifi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wifi = new WiFi();
        $this->wifi::setCommandClass(NetworksCommand::class);
    }

    /**
     * @test
     */
    public function it_should_return_networks()
    {
        $this->assertInstanceOf(Collection::class, $this->wifi::scan());
    }
}
