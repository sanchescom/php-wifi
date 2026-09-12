<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Watchdog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\NmcliBackend;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Test\Support\TestClock;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Watchdog\Watchdog;
use Sanchescom\WiFi\Watchdog\WatchdogConfig;
use Sanchescom\WiFi\Watchdog\WatchdogState;
use Sanchescom\WiFi\WiFi;

final class WatchdogTest extends TestCase
{
    private const DEVICES = "eth0:ethernet:connected\nwlan0:wifi:disconnected\n";

    private const CONNECT_FAILS = [
        'output' => '',
        'exit' => 1,
        'stderr' => 'Error: No network with SSID HomeNet found.',
    ];

    private function hotspotConfig(): HotspotConfig
    {
        return new HotspotConfig('femus-setup', 'a-strong-passphrase');
    }

    private function networkList(bool $connected): string
    {
        $active = $connected ? 'yes' : 'no';
        $bssid = '04\\:8D\\:38\\:22\\:78\\:9E';

        return "{$active}:HomeNet:{$bssid}:Infra:1:2412 MHz:70:WPA2:(none):pair_ccmp group_ccmp psk\n";
    }

    private function watchdog(
        FakeCommandRunner $runner,
        TestClock $clock,
        ?string $ssid = 'HomeNet',
        int $retryAfter = 300,
        bool $requireIdleHotspot = true,
    ): Watchdog {
        $wifi = new WiFi(new NmcliBackend($runner));
        $config = new WatchdogConfig(
            ssid: $ssid,
            hotspot: $this->hotspotConfig(),
            retryAfter: $retryAfter,
            requireIdleHotspot: $requireIdleHotspot,
        );

        return new Watchdog($wifi, $config, $clock, $runner);
    }

    #[Test]
    public function a_client_connection_is_reported_connected_without_touching_the_hotspot(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "\n",
            'device wifi list' => $this->networkList(connected: true),
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Connected, $state);
        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('device wifi connect', $command->describe());
            $this->assertStringNotContainsString('device wifi hotspot', $command->describe());
        }
    }

    #[Test]
    public function a_dropped_connection_is_recovered_by_rejoining_the_target_ssid(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "\n",
            'device wifi list' => $this->networkList(connected: false),
            '-f DEVICE,TYPE device' => self::DEVICES,
            'device wifi connect' => '',
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Recovered, $state);
        $this->assertStringContainsString('device wifi connect', $runner->last()->describe());
    }

    #[Test]
    public function a_join_failure_raises_the_hotspot(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "\n",
            'device wifi list' => $this->networkList(connected: false),
            '-f DEVICE,TYPE device' => self::DEVICES,
            'device wifi connect' => self::CONNECT_FAILS,
            'device wifi hotspot' => '',
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::HotspotRaised, $state);
        $this->assertStringContainsString('device wifi hotspot', $runner->last()->describe());
    }

    #[Test]
    public function a_hotspot_with_attached_stations_is_left_alone(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => "Station 11:22:33:44:55:66 (on wlan0)\nStation aa:bb:cc:dd:ee:ff (on wlan0)\n",
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::HotspotBusy, $state);
        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('connection down', $command->describe());
            $this->assertStringNotContainsString('device wifi list', $command->describe());
        }
    }

    #[Test]
    public function an_idle_hotspot_before_retry_after_elapses_is_left_alone(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => '',
        ]);
        $clock = new TestClock(1_000);
        $watchdog = $this->watchdog($runner, $clock, retryAfter: 300);

        $state = $watchdog->tick();
        $clock->sleep(299);
        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::HotspotBusy, $state);
        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('connection down', $command->describe());
        }
    }

    #[Test]
    public function an_idle_hotspot_past_retry_after_is_stopped_and_a_successful_reconnect_recovers(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => '',
            'connection down' => '',
            'device wifi connect' => '',
        ]);
        $clock = new TestClock(1_000);
        $watchdog = $this->watchdog($runner, $clock, retryAfter: 300);

        $watchdog->tick();
        $clock->sleep(300);
        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Recovered, $state);
        $this->assertStringContainsString('connection down', implode(' | ', array_map(
            static fn ($command) => $command->describe(),
            $runner->commands,
        )));
    }

    #[Test]
    public function an_idle_hotspot_past_retry_after_whose_reconnect_fails_is_raised_again(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => '',
            'connection down' => '',
            'device wifi connect' => self::CONNECT_FAILS,
            'device wifi hotspot' => '',
        ]);
        $clock = new TestClock(1_000);
        $watchdog = $this->watchdog($runner, $clock, retryAfter: 300);

        $watchdog->tick();
        $clock->sleep(300);
        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::HotspotRaised, $state);
        $this->assertStringContainsString('device wifi hotspot', $runner->last()->describe());
    }

    #[Test]
    public function a_missing_iw_binary_is_treated_as_no_stations_attached(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => ['output' => '', 'exit' => 127, 'stderr' => 'iw: command not found'],
        ]);
        $watchdog = $this->watchdog($runner, new TestClock(1_000), retryAfter: 300);

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::HotspotBusy, $state);
        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('connection down', $command->describe());
        }
    }

    #[Test]
    public function a_null_ssid_tries_every_known_network_in_turn(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "\n",
            'device wifi list' => $this->networkList(connected: false),
            '-f DEVICE,TYPE device' => self::DEVICES,
            'connection show' => "HomeNet:802-11-wireless:wlan0:no\n",
            'connection show HomeNet' => "HomeNet\n",
            'device wifi connect' => '',
        ]);
        $watchdog = $this->watchdog($runner, new TestClock(), ssid: null);

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Recovered, $state);
        $this->assertStringContainsString('device wifi connect', $runner->last()->describe());
    }

    #[Test]
    public function a_non_positive_interval_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);

        new WatchdogConfig(ssid: null, hotspot: $this->hotspotConfig(), interval: 0);
    }

    #[Test]
    public function a_non_positive_retry_after_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);

        new WatchdogConfig(ssid: null, hotspot: $this->hotspotConfig(), retryAfter: -1);
    }
}
