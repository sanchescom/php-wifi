<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\System\Collection;
use Sanchescom\WiFi\Test\Darwin\Mocks\SystemProfilerCommand;
use Sanchescom\WiFi\Test\Darwin\Mocks\SystemProfilerRedactedCommand;
use Sanchescom\WiFi\Test\Darwin\Mocks\SystemProfilerWithAwdlCommand;
use Sanchescom\WiFi\WiFi;

class DarwinSystemProfilerTest extends BaseTestCase
{
    private function scan(string $commandClass): Collection
    {
        WiFi::setCommandClass($commandClass);
        WiFi::setPhpOperationSystem('Darwin');

        return WiFi::scan();
    }

    #[Test]
    public function it_parses_the_wifi_interface_only(): void
    {
        $networks = $this->scan(SystemProfilerCommand::class);

        // one entry per network under en0 (current + others); nothing from awdl0
        $this->assertSame(6, $networks->count());
        $this->assertSame(
            ['AlphaNet-foiEmE', 'Offshore View Marine Services'],
            $networks->take(2)->pluck('ssid')->all()
        );
    }

    #[Test]
    public function it_reads_channel_security_and_signal(): void
    {
        $current = $this->scan(SystemProfilerCommand::class)->getBySsid('AlphaNet-foiEmE');

        $this->assertSame(157, $current->channel);
        $this->assertSame('WPA2 Personal', $current->security);
        $this->assertSame(-62.0, $current->dbm);
        $this->assertSame(76.0, $current->quality);
        $this->assertSame('', $current->bssid, 'system_profiler never reports a BSSID');
    }

    #[Test]
    public function only_the_current_network_is_connected(): void
    {
        $networks = $this->scan(SystemProfilerCommand::class);

        $this->assertSame(['AlphaNet-foiEmE'], array_values(array_map(
            fn ($network) => $network->ssid,
            $networks->getConnected()
        )));
    }

    #[Test]
    public function redacted_networks_are_never_reported_as_connected(): void
    {
        $networks = $this->scan(SystemProfilerRedactedCommand::class);

        $this->assertSame(6, $networks->count());
        $this->assertSame(['<redacted>'], $networks->pluck('ssid')->unique()->values()->all());
        $this->assertSame([], $networks->getConnected());
    }

    #[Test]
    public function it_ignores_networks_listed_under_other_interfaces(): void
    {
        $networks = $this->scan(SystemProfilerWithAwdlCommand::class);

        $this->assertSame(6, $networks->count());
        $this->assertNotContains('Peer-To-Peer-Ghost', $networks->pluck('ssid')->all());
    }
}
