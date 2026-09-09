<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Nmcli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Nmcli\ConnectionListParser;
use Sanchescom\WiFi\Value\Device;

final class ConnectionListParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/linux';

    #[Test]
    public function it_keeps_only_wireless_connections_in_order(): void
    {
        $output = (string) file_get_contents(self::FIXTURES . '/Connections.txt');

        $networks = (new ConnectionListParser())->parse($output);

        $this->assertCount(3, $networks);

        $this->assertSame('BELL340', $networks[0]->name);
        $this->assertSame('BELL340', $networks[0]->ssid);
        $this->assertEquals(new Device('wlan0'), $networks[0]->device);
        $this->assertTrue($networks[0]->active);

        $this->assertSame('Cafe: Corner', $networks[1]->name);
        $this->assertSame('Cafe: Corner', $networks[1]->ssid);
        $this->assertNull($networks[1]->device);
        $this->assertFalse($networks[1]->active);

        $this->assertSame('Hotspot', $networks[2]->name);
        $this->assertNull($networks[2]->device);
        $this->assertFalse($networks[2]->active);
    }
}
