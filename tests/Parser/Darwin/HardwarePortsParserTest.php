<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Darwin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Darwin\HardwarePortsParser;
use Sanchescom\WiFi\Value\Device;

final class HardwarePortsParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/darwin';

    #[Test]
    public function it_finds_the_device_behind_the_wifi_hardware_port(): void
    {
        $device = (new HardwarePortsParser())->parse(self::fixture('HardwarePorts.txt'));

        $this->assertEquals(new Device('en0'), $device);
    }

    #[Test]
    public function it_returns_null_when_there_is_no_wifi_or_airport_port(): void
    {
        $device = (new HardwarePortsParser())->parse(
            "Hardware Port: Ethernet Adapter (en4)\nDevice: en4\nEthernet Address: 00:00:00:00:00:00\n",
        );

        $this->assertNull($device);
    }

    #[Test]
    public function it_recognises_the_older_airport_hardware_port_name(): void
    {
        $device = (new HardwarePortsParser())->parse(
            "Hardware Port: AirPort\nDevice: en1\nEthernet Address: 00:00:00:00:00:00\n",
        );

        $this->assertEquals(new Device('en1'), $device);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }
}
