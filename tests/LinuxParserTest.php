<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksColonCommand;
use Sanchescom\WiFi\WiFi;

class LinuxParserTest extends BaseTestCase
{
    #[Test]
    public function an_ssid_containing_a_colon_does_not_shift_the_fields(): void
    {
        WiFi::setCommandClass(NetworksColonCommand::class);
        WiFi::setPhpOperationSystem('Linux');

        $networks = WiFi::scan();

        $this->assertSame(3, $networks->count());
        $cafe = $networks->getBySsid('Cafe: Corner');
        $this->assertSame('70:9F:2D:97:F7:F7', $cafe->bssid);
        $this->assertSame(3, $cafe->channel);
        $this->assertSame(2422, $cafe->frequency);
        $this->assertSame(45.0, $cafe->quality);
        $this->assertSame('WPA2', $cafe->security);
        $this->assertTrue($cafe->connected);

        $hidden = $networks->getByBssid('2C:99:24:3C:A2:F9');
        $this->assertSame('', $hidden->ssid);
        $this->assertSame(1, $hidden->channel);
    }
}
