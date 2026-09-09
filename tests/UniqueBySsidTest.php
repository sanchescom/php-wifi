<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksPiCommand;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksWeakFirstCommand;
use Sanchescom\WiFi\WiFi;

class UniqueBySsidTest extends BaseTestCase
{
    #[Test]
    public function it_keeps_the_strongest_radio_per_ssid_and_every_hidden_network(): void
    {
        WiFi::setCommandClass(NetworksPiCommand::class);
        WiFi::setPhpOperationSystem('Linux');

        $unique = WiFi::scan()->uniqueBySsid();

        $this->assertSame(['BELL340', '[LG_WashTower]ef1f', 'VTECH_5764_9764', ''], $unique->pluck('ssid')->all());
        $this->assertSame('02:00:00:00:00:01', $unique->getBySsid('BELL340')->bssid, 'the 2.4 GHz radio at 100 % wins over the 5 GHz one at 69 %');
        $this->assertSame(4, $unique->count());
    }

    #[Test]
    public function the_strongest_radio_wins_even_when_it_is_not_first(): void
    {
        WiFi::setCommandClass(NetworksWeakFirstCommand::class);
        WiFi::setPhpOperationSystem('Linux');

        $unique = WiFi::scan()->uniqueBySsid();

        $this->assertSame(['BELL340', 'VTECH_5764_9764'], $unique->pluck('ssid')->all(), 'order of first appearance is kept');
        $this->assertSame('02:00:00:00:00:01', $unique->getBySsid('BELL340')->bssid, 'the 100 % radio wins over the 69 % one listed first');
    }
}
