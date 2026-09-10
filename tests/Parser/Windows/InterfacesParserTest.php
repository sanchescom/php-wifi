<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Windows;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Windows\InterfacesParser;
use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Device;

final class InterfacesParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/windows';

    #[Test]
    public function it_reads_the_device_connected_bssid_and_ssid(): void
    {
        $info = (new InterfacesParser())->parse(self::fixture('Interfaces.txt'));

        $this->assertEquals(new Device('Wireless'), $info->device);
        $this->assertEquals(Bssid::from('04:8d:38:22:78:9e'), $info->connectedBssid);
        $this->assertSame('AlphaNet-foiEmE', $info->connectedSsid);
    }

    #[Test]
    public function it_tolerates_crlf_line_endings(): void
    {
        $crlf = str_replace("\n", "\r\n", self::fixture('Interfaces.txt'));

        $info = (new InterfacesParser())->parse($crlf);

        $this->assertEquals(new Device('Wireless'), $info->device);
        $this->assertSame('AlphaNet-foiEmE', $info->connectedSsid);
    }

    #[Test]
    public function a_disconnected_interface_has_no_bssid_or_ssid(): void
    {
        $output = <<<'TXT'
            There is 1 interface on the system:

                Name                   : Wireless
                Description            : Intel(R) Centrino(R) Wireless-N 2230
                GUID                   : 977397df-f447-4702-a9fe-539badd75a02
                Physical address       : 68:17:29:b8:25:ae
                State                  : disconnected

            TXT;

        $info = (new InterfacesParser())->parse($output);

        $this->assertEquals(new Device('Wireless'), $info->device);
        $this->assertNull($info->connectedBssid);
        $this->assertNull($info->connectedSsid);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }
}
