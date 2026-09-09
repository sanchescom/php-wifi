<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Nmcli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Nmcli\DeviceListParser;
use Sanchescom\WiFi\Value\Device;

final class DeviceListParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/linux';

    #[Test]
    public function it_returns_the_first_device_of_type_wifi(): void
    {
        $device = (new DeviceListParser())->parse((string) file_get_contents(self::FIXTURES . '/Devices.txt'));

        $this->assertEquals(new Device('wlan0'), $device);
    }

    #[Test]
    public function it_returns_null_when_no_device_is_of_type_wifi(): void
    {
        $device = (new DeviceListParser())->parse("eth0:ethernet:connected\n");

        $this->assertNull($device);
    }

    #[Test]
    public function wifi_p2p_never_matches(): void
    {
        $device = (new DeviceListParser())->parse("p2p-dev-wlan0:wifi-p2p:disconnected\n");

        $this->assertNull($device);
    }
}
