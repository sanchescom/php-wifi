<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Watchdog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sanchescom\WiFi\Backend\NmcliBackend;
use Sanchescom\WiFi\Backend\WpaCliBackend;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Test\Support\TestClock;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Watchdog\Clock;
use Sanchescom\WiFi\Watchdog\Watchdog;
use Sanchescom\WiFi\Watchdog\WatchdogConfig;
use Sanchescom\WiFi\Watchdog\WatchdogState;
use Sanchescom\WiFi\WiFi;

final class WatchdogTest extends TestCase
{
    private const DEVICES = "eth0:ethernet:connected\nwlan0:wifi:disconnected\n";

    private const WPACLI_FIXTURES = __DIR__ . '/../Fixtures/wpacli';

    /** Where `which iw` resolves to — /usr/sbin, off an unprivileged user's PATH. */
    private const IW = '/usr/sbin/iw';

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

    /** @param array<string, mixed> $fixtures a `which iw` fixture here overrides the resolved default */
    private static function runner(array $fixtures): FakeCommandRunner
    {
        return new FakeCommandRunner(array_merge(['which iw' => self::IW . "\n"], $fixtures));
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
        Clock $clock,
        ?string $ssid = 'HomeNet',
        int $retryAfter = 300,
        bool $requireIdleHotspot = true,
        ?string $stateFile = null,
        ?Device $device = null,
        int $interval = 30,
    ): Watchdog {
        $wifi = new WiFi(new NmcliBackend($runner));
        $config = new WatchdogConfig(
            ssid: $ssid,
            hotspot: $this->hotspotConfig(),
            interval: $interval,
            retryAfter: $retryAfter,
            requireIdleHotspot: $requireIdleHotspot,
            device: $device,
        );

        return new Watchdog($wifi, $config, $clock, $runner, $stateFile ?? $this->freshStateFile());
    }

    #[Test]
    public function a_client_connection_is_reported_connected_without_touching_the_hotspot(): void
    {
        $runner = self::runner([
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
        $runner = self::runner([
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
        $runner = self::runner([
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
        $runner = self::runner([
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
        $runner = self::runner([
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
        $runner = self::runner([
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
        $runner = self::runner([
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
        $runner = self::runner([
            'connection show --active' => self::HOTSPOT_ACTIVE_FAILS,
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Failed, $state);
    }

    /**
     * "Cannot count stations" must read as "someone may be attached", never
     * as "nobody is": elapse retryAfter, so only the station count stands
     * between the hotspot and teardown, and confirm it is still left up.
     * Covers both ways the count can be unknown.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unknownStationCounts(): array
    {
        return [
            'iw cannot be resolved' => [['which iw' => ['output' => '', 'exit' => 1]]],
            'station dump fails' => [['station dump' => ['output' => '', 'exit' => 1, 'stderr' => 'command failed']]],
        ];
    }

    /** @param array<string, mixed> $stationFixture */
    #[Test]
    #[DataProvider('unknownStationCounts')]
    public function an_unknown_station_count_past_retry_after_leaves_the_hotspot_up(array $stationFixture): void
    {
        $runner = self::runner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => '',
            'connection down' => '',
            'device wifi connect' => '',
            ...$stationFixture,
        ]);
        $clock = new TestClock(1_000);
        $watchdog = $this->watchdog($runner, $clock, retryAfter: 300);

        $watchdog->tick();
        $clock->sleep(300);
        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::HotspotBusy, $state);
        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('connection down', $command->describe());
        }
    }

    /** Opting out of the idle check ignores the count entirely, so an unknown one must not block teardown. */
    #[Test]
    public function an_unresolvable_iw_does_not_block_teardown_when_the_idle_check_is_off(): void
    {
        $runner = self::runner([
            'which iw' => ['output' => '', 'exit' => 1],
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'connection down' => '',
            'device wifi connect' => '',
        ]);
        $clock = new TestClock(1_000);
        $watchdog = $this->watchdog($runner, $clock, retryAfter: 300, requireIdleHotspot: false);

        $watchdog->tick();
        $clock->sleep(300);

        $this->assertSame(WatchdogState::Recovered, $watchdog->tick());
    }

    /** A bare `iw` exits 127 on an unprivileged PATH; the dump must run the resolved absolute path. */
    #[Test]
    public function the_station_dump_runs_iw_by_its_resolved_absolute_path(): void
    {
        $runner = self::runner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => "Station 11:22:33:44:55:66 (on wlan0)\n",
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $this->assertSame(WatchdogState::HotspotBusy, $watchdog->tick());
        $this->assertSame(self::IW, $runner->last()->program);
        $this->assertSame(self::IW . ' dev wlan0 station dump', $runner->last()->describe());
    }

    /**
     * A Pi with no RTC can boot with "now" earlier than the persisted raise
     * time. The countdown must restart from the skewed clock and still
     * complete — not sit at a negative age that never reaches retryAfter.
     */
    #[Test]
    public function a_clock_that_jumps_behind_the_raise_time_still_lets_retry_after_elapse(): void
    {
        $runner = self::runner([
            'connection show --active' => "Hotspot\n",
            '-f DEVICE,TYPE device' => self::DEVICES,
            'station dump' => '',
            'connection down' => '',
            'device wifi connect' => '',
        ]);
        $clock = new TestClock(100_000);
        $watchdog = $this->watchdog($runner, $clock, retryAfter: 300);

        $watchdog->tick();
        $clock->set(1_000);
        $this->assertSame(WatchdogState::HotspotBusy, $watchdog->tick());

        $clock->sleep(300);

        $this->assertSame(WatchdogState::Recovered, $watchdog->tick());
    }

    /** HostapdConfig and friends throw plain RuntimeException; a tick must still survive it. */
    #[Test]
    public function an_exception_outside_the_wifi_hierarchy_is_reported_as_failed(): void
    {
        $watchdog = $this->watchdog(self::runner([]), new TestClock());

        $this->assertSame(WatchdogState::Failed, $watchdog->tick());
        $this->assertStringContainsString('No fixture for command', (string) $watchdog->lastError());
    }

    /**
     * The seam the final review found: the watchdog was only ever run
     * against nmcli. On wpa_cli, rejoining with no credentials must select
     * the SSID's existing (WPA2) block, never add an open-network one.
     */
    #[Test]
    public function on_wpa_cli_a_dropped_connection_is_rejoined_through_the_existing_network_block(): void
    {
        $runner = self::runner([
            'which wpa_cli' => "/usr/sbin/wpa_cli\n",
            'which' => ['output' => '', 'exit' => 1],
            'scan_results' => self::WPACLI_FIXTURES . '/ScanResults.txt',
            'scan' => "OK\n",
            'status' => [self::WPACLI_FIXTURES . '/StatusInactive.txt', self::WPACLI_FIXTURES . '/Status.txt'],
            'list_networks' => self::WPACLI_FIXTURES . '/ListNetworks.txt',
            'select_network' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0', sleep: static function (): void {
        });
        $config = new WatchdogConfig(ssid: 'BELL340', hotspot: $this->hotspotConfig(), device: new Device('wlan0'));
        $watchdog = new Watchdog(new WiFi($backend), $config, new TestClock(), $runner, $this->freshStateFile());

        $this->assertSame(WatchdogState::Recovered, $watchdog->tick());

        $stdin = implode("\n", array_map(static fn ($command): string => (string) $command->stdin, $runner->commands));
        $this->assertStringContainsString('select_network 0', $stdin);
        $this->assertStringNotContainsString('add_network', $stdin);
        $this->assertStringNotContainsString('key_mgmt', $stdin);
    }

    #[Test]
    public function a_null_ssid_tries_every_known_network_in_turn(): void
    {
        $runner = self::runner([
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
        $runner = self::runner([
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

        $runner = self::runner([
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
        $runner = self::runner([
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

    /**
     * On a multi-radio host, a pinned device must govern reconnection too,
     * not just the hotspot it raises — otherwise a reconnect attempt could
     * run against whatever WiFi::device() happens to auto-detect, which
     * need not be the interface the operator configured.
     */
    #[Test]
    public function a_configured_device_is_used_to_reconnect_without_auto_detecting_one(): void
    {
        $runner = self::runner([
            'connection show --active' => "\n",
            'device wifi list' => $this->networkList(connected: false),
            'device wifi connect' => '',
        ]);
        $watchdog = $this->watchdog($runner, new TestClock(), device: new Device('wlan1'));

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Recovered, $state);
        $this->assertStringContainsString('wlan1', $runner->last()->describe());

        // No "-f DEVICE,TYPE device" fixture exists; if the Watchdog had
        // called WiFi::device() anyway, the FakeCommandRunner would have
        // thrown "No fixture for command" instead of reaching this line.
        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('DEVICE,TYPE', $command->describe());
        }
    }

    /** The station-dump side of the same pinning, while a hotspot is up. */
    #[Test]
    public function a_configured_device_is_used_for_the_station_dump_without_auto_detecting_one(): void
    {
        $runner = self::runner([
            'connection show --active' => "Hotspot\n",
            'station dump' => "Station 11:22:33:44:55:66 (on wlan1)\n",
        ]);
        $watchdog = $this->watchdog($runner, new TestClock(), device: new Device('wlan1'));

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::HotspotBusy, $state);
        $this->assertStringContainsString('wlan1', $runner->last()->describe());
        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('DEVICE,TYPE', $command->describe());
        }
    }

    /** Without a configured device, both call sites fall back to detection, as before this option existed. */
    #[Test]
    public function without_a_configured_device_reconnect_falls_back_to_the_detected_one(): void
    {
        $runner = self::runner([
            'connection show --active' => "\n",
            'device wifi list' => $this->networkList(connected: false),
            '-f DEVICE,TYPE device' => self::DEVICES,
            'device wifi connect' => '',
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Recovered, $state);
        $this->assertStringContainsString('wlan0', $runner->last()->describe());
    }

    #[Test]
    public function a_failed_tick_records_the_underlying_error_message(): void
    {
        $runner = self::runner([
            'connection show --active' => self::HOTSPOT_ACTIVE_FAILS,
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $state = $watchdog->tick();

        $this->assertSame(WatchdogState::Failed, $state);
        $this->assertStringContainsString('NetworkManager is not running', (string) $watchdog->lastError());
    }

    #[Test]
    public function last_error_is_null_when_the_most_recent_tick_did_not_fail(): void
    {
        $runner = self::runner([
            'connection show --active' => "\n",
            'device wifi list' => $this->networkList(connected: true),
        ]);
        $watchdog = $this->watchdog($runner, new TestClock());

        $watchdog->tick();

        $this->assertNull($watchdog->lastError());
    }

    /**
     * run() must call the observer with every tick's state so a caller
     * (the CLI, logging to STDERR) can report liveness without run() doing
     * any I/O itself. run() never returns, so the fake Clock below throws
     * once it has let two ticks through — the only way to observe a
     * "forever" loop's behaviour without actually waiting forever.
     */
    #[Test]
    public function run_calls_the_observer_with_the_state_after_every_tick(): void
    {
        $runner = self::runner([
            'connection show --active' => "\n",
            'device wifi list' => $this->networkList(connected: true),
        ]);
        $clock = new class implements Clock {
            /** @var list<int> */
            public array $slept = [];

            private int $now = 1_000;

            public function now(): int
            {
                return $this->now;
            }

            public function sleep(int $seconds): void
            {
                $this->slept[] = $seconds;

                if (count($this->slept) >= 2) {
                    throw new RuntimeException('stop the loop');
                }

                $this->now += $seconds;
            }
        };
        $watchdog = $this->watchdog($runner, $clock, interval: 17);
        $states = [];

        try {
            $watchdog->run(static function (WatchdogState $state) use (&$states): void {
                $states[] = $state;
            });
            $this->fail('run() must never return.');
        } catch (RuntimeException $exception) {
            $this->assertSame('stop the loop', $exception->getMessage());
        }

        $this->assertSame([WatchdogState::Connected, WatchdogState::Connected], $states);
        $this->assertSame([17, 17], $clock->slept, 'run() must sleep the configured interval between ticks');
    }
}
