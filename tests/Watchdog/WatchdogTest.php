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

    private const HOTSPOT_ACTIVE_FAILS = [
        'output' => '',
        'exit' => 1,
        'stderr' => 'Error: NetworkManager is not running.',
    ];

    /** @var list<string> every state file this test created, unlinked in tearDown() */
    private array $stateFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->stateFiles as $stateFile) {
            if (is_file($stateFile)) {
                unlink($stateFile);
            }
        }

        parent::tearDown();
    }

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

    /** A fresh path per call, so tests never share persisted watchdog state. */
    private function freshStateFile(): string
    {
        $path = sys_get_temp_dir() . '/php-wifi-watchdog-test-' . bin2hex(random_bytes(8)) . '.json';
        $this->stateFiles[] = $path;

        return $path;
    }

    private function watchdog(
        FakeCommandRunner $runner,
        TestClock $clock,
        ?string $ssid = 'HomeNet',
        int $retryAfter = 300,
        bool $requireIdleHotspot = true,
        ?string $stateFile = null,
    ): Watchdog {
        $wifi = new WiFi(new NmcliBackend($runner));
        $config = new WatchdogConfig(
            ssid: $ssid,
            hotspot: $this->hotspotConfig(),
            retryAfter: $retryAfter,
            requireIdleHotspot: $requireIdleHotspot,
        );

        return new Watchdog($wifi, $config, $clock, $runner, $stateFile ?? $this->freshStateFile());
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

        $watchdog->tick();
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

    /**
     * A tick where isHotspotActive() itself blows up is the kind of failure
     * none of the happy paths produce — it must come out the other side as
     * Failed, and tick() must not let the exception escape.
     */
    #[Test]
    public function an_unexpected_failure_while_checking_the_hotspot_is_reported_as_failed(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => self::HOTSPOT_ACTIVE_FAILS,
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Failed, $state);
    }

    /**
     * A missing iw must be read as "idle", not "occupied forever": elapse
     * retryAfter and confirm the watchdog actually proceeds to stop the
     * hotspot and attempt a reconnect. Asserting HotspotBusy alone (the
     * previous version of this test) cannot tell "treated as idle" apart
     * from "treated as busy" — both produce HotspotBusy before retryAfter
     * elapses regardless of how the missing binary is read.
     */
    #[Test]
    public function a_missing_iw_binary_is_treated_as_no_stations_attached(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => ['output' => '', 'exit' => 127, 'stderr' => 'iw: command not found'],
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

    /**
     * The age of the hotspot must survive this process dying and a fresh
     * Watchdog taking over — otherwise a restart resets the retry clock and
     * an idle hotspot could stand forever.
     */
    #[Test]
    public function the_hotspots_age_survives_a_new_watchdog_instance_pointed_at_the_same_state_file(): void
    {
        $stateFile = $this->freshStateFile();
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => '',
            'connection down' => '',
            'device wifi connect' => '',
        ]);
        $clock = new TestClock(1_000);

        // First instance raises (records) the hotspot's start time and is discarded, as if the process died.
        $this->watchdog($runner, $clock, retryAfter: 300, stateFile: $stateFile)->tick();

        // A brand new instance, no in-memory state, reads the same file back.
        $clock->sleep(300);
        $state = $this->watchdog($runner, $clock, retryAfter: 300, stateFile: $stateFile)->tick();

        $this->assertSame(WatchdogState::Recovered, $state);
    }

    #[Test]
    public function a_corrupt_state_file_falls_back_to_treating_the_hotspot_as_just_raised(): void
    {
        $stateFile = $this->freshStateFile();
        file_put_contents($stateFile, 'not valid json {{{');

        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => '',
        ]);
        $watchdog = $this->watchdog($runner, new TestClock(1_000), retryAfter: 300, stateFile: $stateFile);

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::HotspotBusy, $state);
        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('connection down', $command->describe());
        }
    }

    #[Test]
    public function the_state_file_is_removed_once_a_reconnect_succeeds(): void
    {
        $stateFile = $this->freshStateFile();
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => '',
            'connection down' => '',
            'device wifi connect' => '',
        ]);
        $clock = new TestClock(1_000);
        $watchdog = $this->watchdog($runner, $clock, retryAfter: 300, stateFile: $stateFile);

        $watchdog->tick();
        $this->assertFileExists($stateFile, 'the hotspot was just raised, its start time should be on disk');

        $clock->sleep(300);
        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Recovered, $state);
        $this->assertFileDoesNotExist($stateFile);
    }
}
