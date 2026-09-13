<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\WpaCliBackend;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\Hotspot;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Value\NetworkCollection;

final class WpaCliBackendTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures';

    private const HOSTAPD_PID_FILE = '/php-wifi-hostapd.pid';

    private const DNSMASQ_PID_FILE = '/php-wifi-dnsmasq.pid';

    /**
     * Fake absolute paths `which` resolves each tool to, standing in for
     * where they actually live on Debian/Raspberry Pi OS (`/usr/sbin`, not
     * on an unprivileged user's PATH) — the whole point of this defect fix.
     */
    private const WPA_CLI = '/usr/sbin/wpa_cli';

    private const IW = '/usr/sbin/iw';

    private const IP = '/usr/sbin/ip';

    private const HOSTAPD = '/usr/sbin/hostapd';

    private const DNSMASQ = '/usr/sbin/dnsmasq';

    protected function setUp(): void
    {
        parent::setUp();
        $this->removeHotspotPidFiles();
    }

    protected function tearDown(): void
    {
        $this->removeHotspotPidFiles();
        parent::tearDown();
    }

    private function removeHotspotPidFiles(): void
    {
        @unlink(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);
        @unlink(sys_get_temp_dir() . self::DNSMASQ_PID_FILE);
    }

    /** @param array<string, string|array{output: string, exit?: int, stderr?: string}> $fixtures */
    private static function runner(array $fixtures): FakeCommandRunner
    {
        return new FakeCommandRunner(array_merge([
            'which wpa_cli' => self::WPA_CLI . "\n",
            'which iw' => self::IW . "\n",
            'which ip' => self::IP . "\n",
            'which hostapd' => self::HOSTAPD . "\n",
            'which dnsmasq' => self::DNSMASQ . "\n",
            'select_network' => "OK\n",
            'enable_network' => "OK\n",
            'save_config' => "OK\n",
        ], $fixtures));
    }

    // --- detectDevice() -----------------------------------------------------

    #[Test]
    public function detect_device_returns_the_first_wireless_interface(): void
    {
        $runner = self::runner([self::IW . ' dev' => self::FIXTURES . '/iw/Dev.txt']);
        $backend = new WpaCliBackend($runner);

        $device = $backend->detectDevice();

        $this->assertEquals(new Device('wlan0'), $device);
        $this->assertSame(self::IW, $runner->last()->program);
        $this->assertSame(self::IW . ' dev', $runner->last()->describe());
        $this->assertNull($runner->last()->stdin);
    }

    #[Test]
    public function detect_device_throws_when_iw_dev_lists_no_wireless_interface(): void
    {
        $runner = self::runner([self::IW . ' dev' => "phy#0\n"]);
        $backend = new WpaCliBackend($runner);

        $this->expectException(DeviceNotFound::class);

        $backend->detectDevice();
    }

    #[Test]
    public function detect_device_throws_a_clear_error_when_iw_cannot_be_resolved(): void
    {
        $runner = new FakeCommandRunner(['which iw' => ['output' => '', 'exit' => 1]]);
        $backend = new WpaCliBackend($runner);

        try {
            $backend->detectDevice();
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('iw', $exception->getMessage());
            $this->assertStringContainsString('not found', $exception->getMessage());
        }

        $this->assertCount(1, $runner->commands);
    }

    #[Test]
    public function the_configured_interface_short_circuits_detect_device(): void
    {
        $runner = self::runner([
            'scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt',
            'scan' => '',
            'status' => self::FIXTURES . '/wpacli/StatusInactive.txt',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->scan();

        foreach ($runner->commands as $command) {
            $this->assertNotSame(self::IW, $command->program);
        }
    }

    // --- scan() ---------------------------------------------------------------

    /**
     * The happy path: `scan_results` already has rows on the very first
     * read (a `wpa_supplicant` that had already finished a previous scan),
     * so {@see WpaCliBackend::scan()} never polls a second time — exactly
     * one `scan_results` call, same as before this defect was fixed. A
     * trailing `status` call then reports the interface as associated to
     * BELL340 by BSSID, which marks exactly that row — the first of the
     * four ({@see \Sanchescom\WiFi\Value\Network::$connected}) — and leaves
     * the other three false.
     */
    /** A scan already in progress answers FAIL-BUSY; its results still arrive, so scan() must read them. */
    #[Test]
    public function scan_reads_the_results_of_a_scan_already_in_progress(): void
    {
        $runner = self::runner([
            'scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt',
            'scan' => "FAIL-BUSY\n",
            'status' => self::FIXTURES . '/wpacli/StatusInactive.txt',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->assertCount(4, $backend->scan());
    }

    #[Test]
    public function scan_still_throws_on_any_other_scan_failure(): void
    {
        $runner = self::runner([
            'scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt',
            'scan' => "FAIL\n",
            'status' => self::FIXTURES . '/wpacli/StatusInactive.txt',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->expectException(CommandFailed::class);

        $backend->scan();
    }

    #[Test]
    public function scan_triggers_a_scan_then_reads_scan_results(): void
    {
        $runner = self::runner([
            'scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt',
            'scan' => '',
            'status' => self::FIXTURES . '/wpacli/StatusAssociatedBell340.txt',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $networks = $backend->scan();

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertCount(4, $networks);
        $this->assertTrue($networks[0]->connected);
        $this->assertFalse($networks[1]->connected);
        $this->assertFalse($networks[2]->connected);
        $this->assertFalse($networks[3]->connected);

        $this->assertCount(4, $runner->commands);
        $this->assertEquals(new Command('which', ['wpa_cli'], ['PATH' => self::searchPath()]), $runner->commands[0]);
        $this->assertEquals(
            new Command(self::WPA_CLI, ['-i', 'wlan0', 'scan'], ['LANG' => 'C']),
            $runner->commands[1],
        );
        $this->assertEquals(
            new Command(self::WPA_CLI, ['-i', 'wlan0', 'scan_results'], ['LANG' => 'C']),
            $runner->commands[2],
        );
        $this->assertEquals(
            new Command(self::WPA_CLI, ['-i', 'wlan0', 'status'], ['LANG' => 'C']),
            $runner->commands[3],
        );
        $this->assertSame(self::WPA_CLI, $runner->commands[1]->program);
        $this->assertSame(self::WPA_CLI, $runner->commands[2]->program);
        $this->assertSame(self::WPA_CLI, $runner->commands[3]->program);
    }

    /**
     * The defect fixed in 3.2: a cold `wpa_supplicant` replies to the first
     * few `scan_results` reads with nothing while the scan is still
     * running, then starts returning rows. `scan()` must keep polling
     * through exactly that shape and still return the eventual rows — via
     * an injected no-op {@see \Closure} standing in for {@see
     * WpaCliBackend::$sleep}, so this test never actually waits.
     */
    #[Test]
    public function scan_polls_scan_results_until_a_cold_supplicant_finishes_scanning(): void
    {
        $sleeps = [];
        $runner = self::runner([
            'scan_results' => ['', '', self::FIXTURES . '/wpacli/ScanResults.txt'],
            'scan' => '',
            'status' => self::FIXTURES . '/wpacli/StatusInactive.txt',
        ]);
        $backend = new WpaCliBackend(
            $runner,
            'wlan0',
            sleep: function (int $microseconds) use (&$sleeps): void {
                $sleeps[] = $microseconds;
            },
        );

        $startedAt = microtime(true);
        $networks = $backend->scan();
        $elapsed = microtime(true) - $startedAt;

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertCount(4, $networks);

        // which wpa_cli, scan, scan_results three times, then status.
        $this->assertCount(6, $runner->commands);
        $this->assertSame(self::WPA_CLI, $runner->commands[1]->program);
        $this->assertSame(['-i', 'wlan0', 'scan'], $runner->commands[1]->arguments);

        foreach ([2, 3, 4] as $index) {
            $this->assertSame(self::WPA_CLI, $runner->commands[$index]->program);
            $this->assertSame(['-i', 'wlan0', 'scan_results'], $runner->commands[$index]->arguments);
        }

        $this->assertSame(self::WPA_CLI, $runner->commands[5]->program);
        $this->assertSame(['-i', 'wlan0', 'status'], $runner->commands[5]->arguments);

        // One pause between each pair of reads: two, not three, since the
        // third (successful) read never needs to wait for another.
        $this->assertSame([500_000, 500_000], $sleeps);

        // The sleeper is a no-op closure: nothing here ever really waited.
        $this->assertLessThan(1.0, $elapsed);
    }

    /**
     * Exhausting the poll budget is not an error: a genuinely empty area
     * has no networks, and returning an empty collection is the correct,
     * valid answer — it is `connect()`'s job, not `scan()`'s, to turn "no
     * match" into {@see \Sanchescom\WiFi\Exception\NetworkNotFound}.
     */
    #[Test]
    public function scan_returns_an_empty_collection_once_the_poll_budget_is_exhausted(): void
    {
        $sleeps = [];
        $runner = self::runner([
            'scan_results' => '',
            'scan' => '',
            'status' => self::FIXTURES . '/wpacli/StatusInactive.txt',
        ]);
        $backend = new WpaCliBackend(
            $runner,
            'wlan0',
            scanAttempts: 4,
            sleep: function (int $microseconds) use (&$sleeps): void {
                $sleeps[] = $microseconds;
            },
        );

        $networks = $backend->scan();

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertCount(0, $networks);

        $scanResultsCalls = array_filter(
            $runner->commands,
            static fn (Command $command): bool => $command->arguments === ['-i', 'wlan0', 'scan_results'],
        );
        $this->assertCount(4, $scanResultsCalls);

        // Three pauses between four reads — never a fifth, wasted one after
        // the last, already-final read.
        $this->assertSame([500_000, 500_000, 500_000], $sleeps);
    }

    #[Test]
    public function scan_throws_a_clear_error_when_wpa_cli_cannot_be_resolved(): void
    {
        $runner = new FakeCommandRunner(['which wpa_cli' => ['output' => '', 'exit' => 1]]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        try {
            $backend->scan();
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('wpa_cli', $exception->getMessage());
            $this->assertStringContainsString('not found', $exception->getMessage());
        }

        $this->assertCount(1, $runner->commands);
    }

    #[Test]
    public function wpa_cli_is_resolved_only_once_per_instance_across_several_calls(): void
    {
        $runner = self::runner([
            'scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt',
            'scan' => '',
            'status' => self::FIXTURES . '/wpacli/StatusInactive.txt',
            'list_networks' => self::FIXTURES . '/wpacli/ListNetworks.txt',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->scan();
        $backend->knownNetworks();

        $whichWpaCli = array_filter(
            $runner->commands,
            static fn (Command $command): bool => $command->describe() === 'which wpa_cli',
        );
        $this->assertCount(1, $whichWpaCli);
    }

    /**
     * Two rows can legitimately share one SSID (two access points of the
     * same mesh, or two unrelated neighbours who happened to pick the same
     * name). `status` reporting a BSSID that only matches the second row
     * must mark that row alone — matching by SSID first would have been
     * unable to tell the two apart.
     */
    #[Test]
    public function only_the_bssid_matching_row_is_marked_when_two_rows_share_one_ssid(): void
    {
        $scanResults = "bssid / frequency / signal level / flags / ssid\n"
            . "aa:bb:cc:dd:ee:01\t2412\t-40\t[WPA2-PSK-CCMP][ESS]\tDuplicateNet\n"
            . "aa:bb:cc:dd:ee:02\t2412\t-55\t[WPA2-PSK-CCMP][ESS]\tDuplicateNet\n";
        $status = "bssid=aa:bb:cc:dd:ee:02\nssid=DuplicateNet\nwpa_state=COMPLETED\n";

        $runner = self::runner([
            'scan_results' => $scanResults,
            'scan' => '',
            'status' => $status,
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $networks = $backend->scan();

        $this->assertCount(2, $networks);
        $this->assertFalse($networks[0]->connected);
        $this->assertTrue($networks[1]->connected);
    }

    /**
     * `wpa_state=INACTIVE` with neither `ssid=` nor `bssid=` printed — the
     * shape captured on real hardware while idle — must leave every row
     * exactly as `scan_results` produced it.
     */
    #[Test]
    public function nothing_is_marked_when_status_reports_no_association(): void
    {
        $runner = self::runner([
            'scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt',
            'scan' => '',
            'status' => self::FIXTURES . '/wpacli/StatusInactive.txt',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $networks = $backend->scan();

        $this->assertCount(4, $networks);
        $this->assertCount(0, $networks->connected());
    }

    /**
     * A `status` call that fails (here, a non-zero exit) must not fail the
     * scan — a scan that works is more useful than no scan, so `scan()`
     * swallows that one failure and returns the rows unmarked.
     */
    #[Test]
    public function a_failing_status_call_does_not_fail_the_scan(): void
    {
        $runner = self::runner([
            'scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt',
            'scan' => '',
            'status' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $networks = $backend->scan();

        $this->assertCount(4, $networks);
        $this->assertCount(0, $networks->connected());
    }

    // --- connect() --------------------------------------------------------

    #[Test]
    public function connect_with_a_password_never_puts_it_in_argv(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::password('p w'), new Device('wlan0'));

        $this->assertSame(
            ['list_networks', 'add_network', '<stdin>', 'select_network 0', 'status', 'save_config'],
            self::wpaCliCalls($runner),
        );

        $script = $runner->commands[3];
        $this->assertSame(self::WPA_CLI, $script->program);
        $this->assertSame(['-i', 'wlan0'], $script->arguments);
        $this->assertSame("set_network 0 ssid \"BELL340\"\nset_network 0 psk \"p w\"\nquit\n", $script->stdin);
        $this->assertTrue($script->stdinIsSecret);

        foreach ($runner->commands as $command) {
            foreach ($command->arguments as $argument) {
                $this->assertStringNotContainsString('p w', $argument);
            }
        }
    }

    /**
     * C3: a corrected passphrase replaces every older block for the SSID —
     * but only once the new block has associated, so the removals and the
     * save come after `status` reports it. The other network's block, enabled
     * before `select_network` disabled it, is enabled again.
     */
    #[Test]
    public function connect_with_a_password_removes_the_old_blocks_only_after_the_new_one_associates(): void
    {
        $runner = self::runner([
            'list_networks' => "network id / ssid / bssid / flags\n"
                . "0\tBELL340\tany\t[DISABLED]\n"
                . "1\tOtherNet\tany\t\n"
                . "2\tBELL340\tany\t\n"
                . "4\tDIRECT-xy\tany\t[P2P-PERSISTENT]\n",
            'remove_network' => "OK\n",
            'add_network' => "3\n",
            'set_network' => "OK\n",
            'status' => "wpa_state=COMPLETED\nid=3\nssid=BELL340\n",
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0', sleep: static function (): void {
        });

        $backend->connect('BELL340', Credentials::password('secret'), new Device('wlan0'));

        $this->assertSame(
            [
                'list_networks', 'add_network', '<stdin>', 'select_network 3', 'status',
                'enable_network 1', 'enable_network 2',
                'remove_network 0', 'remove_network 2', 'save_config',
            ],
            array_slice(self::wpaCliCalls($runner), 0, 10),
        );
    }

    /** C3: a passphrase that never associates must not cost the working block — nothing is removed or saved. */
    #[Test]
    public function connect_with_a_wrong_password_removes_only_the_new_block_and_saves_nothing(): void
    {
        $runner = self::runner([
            'list_networks' => "network id / ssid / bssid / flags\n0\tBELL340\tany\t[CURRENT]\n",
            'remove_network' => "OK\n",
            'add_network' => "1\n",
            'set_network' => "OK\n",
            'status' => "wpa_state=4WAY_HANDSHAKE\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0', 2, sleep: static function (): void {
        });

        try {
            $backend->connect('BELL340', Credentials::password('typo'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('4WAY_HANDSHAKE', $exception->getMessage());
        }

        $this->assertSame(
            ['list_networks', 'add_network', '<stdin>', 'select_network 1', 'status', 'status', 'enable_network 0', 'remove_network 1'],
            self::wpaCliCalls($runner),
        );
    }

    /** A COMPLETED left over from the previous association must not count for the block just selected. */
    #[Test]
    public function connect_waits_for_completed_on_the_new_blocks_id(): void
    {
        $runner = self::runner([
            'list_networks' => "network id / ssid / bssid / flags\n0\tBELL340\tany\t[CURRENT]\n",
            'remove_network' => "OK\n",
            'add_network' => "1\n",
            'set_network' => "OK\n",
            'status' => ["wpa_state=COMPLETED\nid=0\n", "wpa_state=COMPLETED\nid=1\n"],
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0', sleep: static function (): void {
        });

        $backend->connect('BELL340', Credentials::password('secret'), new Device('wlan0'));

        $calls = self::wpaCliCalls($runner);
        $this->assertSame(['status', 'status'], array_values(array_filter($calls, static fn (string $call): bool => $call === 'status')));
        $this->assertContains('remove_network 0', $calls);
    }

    #[Test]
    public function connect_without_credentials_uses_key_mgmt_none_as_arguments(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $this->assertSame(
            ['list_networks', 'add_network', 'set_network 0 ssid "BELL340"', 'set_network 0 key_mgmt NONE', 'select_network 0', 'status', 'save_config'],
            self::wpaCliCalls($runner),
        );
        foreach ($runner->commands as $command) {
            $this->assertNull($command->stdin);
        }
    }

    /**
     * C1: the watchdog's rejoin path calls `connect()` with
     * {@see Credentials::none()} for a network it already knows about. It must
     * select the existing (WPA2) block — never add an open one, never save —
     * and give back the blocks select_network disabled, except one the user
     * had disabled.
     */
    #[Test]
    public function connect_without_credentials_selects_an_existing_block_instead_of_adding_a_new_one(): void
    {
        $runner = self::runner([
            'list_networks' => self::FIXTURES . '/wpacli/ListNetworks.txt',
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $this->assertSame(['list_networks', 'select_network 0', 'status', 'enable_network 1'], self::wpaCliCalls($runner));
    }

    /** C1 for an SSID list_networks prints printf-escaped: the existing block must still be found. */
    #[Test]
    public function connect_without_credentials_finds_an_ssid_that_list_networks_prints_escaped(): void
    {
        $runner = self::runner([
            'list_networks' => "network id / ssid / bssid / flags\n5\tCaf\\xc3\\xa9\tany\t\n",
            'status' => "wpa_state=COMPLETED\nid=5\n",
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->connect('Café', Credentials::none(), new Device('wlan0'));

        $this->assertSame(['list_networks', 'select_network 5', 'status'], self::wpaCliCalls($runner));
    }

    #[Test]
    public function connect_quotes_an_ssid_containing_quotes_and_a_backslash_so_it_round_trips(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "3\n",
            'set_network' => "OK\n",
            'status' => "wpa_state=COMPLETED\nid=3\n",
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $ssid = "He said \"hi\"\\";

        $backend->connect($ssid, Credentials::none(), new Device('wlan0'));

        $ssidCommand = $runner->commands[3];
        $this->assertSame(['-i', 'wlan0', 'set_network', '3', 'ssid'], array_slice($ssidCommand->arguments, 0, 5));
        $ssidLine = 'set_network 3 ssid ' . $ssidCommand->arguments[5];

        $quoted = substr($ssidLine, strlen('set_network 3 ssid '));
        $this->assertStringStartsWith('"', $quoted);
        $this->assertStringEndsWith('"', $quoted);
        $this->assertSame($ssid, self::unescapeWpaSupplicantString($quoted));
    }

    #[Test]
    public function connect_throws_command_failed_when_add_network_replies_fail(): void
    {
        $runner = self::runner(['list_networks' => '', 'add_network' => "FAIL\n"]);
        $backend = new WpaCliBackend($runner);

        try {
            $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed) {
            // which wpa_cli, list_networks, then the failing add_network attempt.
            $this->assertCount(3, $runner->commands);
        }
    }

    /**
     * `status` never reports `COMPLETED` here (the real-hardware shape of
     * the association defect: a cold radio still associating when the
     * budget runs out). Asserts the sleep seam fires between attempts —
     * never after the last, already-final one — with the configured
     * interval, and that the suite still never actually waits despite the
     * production default of ~1.5s per pause.
     */
    #[Test]
    public function connect_throws_command_failed_naming_the_last_state_when_association_never_completes(): void
    {
        $sleeps = [];
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => "wpa_state=SCANNING\n",
        ]);
        $backend = new WpaCliBackend(
            $runner,
            'wlan0',
            3,
            sleep: function (int $microseconds) use (&$sleeps): void {
                $sleeps[] = $microseconds;
            },
        );

        $startedAt = microtime(true);

        try {
            $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('SCANNING', $exception->getMessage());
        }

        $elapsed = microtime(true) - $startedAt;

        $statusCommands = array_filter(
            $runner->commands,
            static fn (Command $command): bool => $command->arguments === ['-i', 'wlan0', 'status'],
        );
        $this->assertCount(3, $statusCommands);

        // Two pauses between three status reads — never a third, wasted one
        // after the last (still-failing) attempt.
        $this->assertSame([1_500_000, 1_500_000], $sleeps);

        // The sleeper is a no-op closure: nothing here ever really waited,
        // despite a production default of ~1.5s per pause.
        $this->assertLessThan(1.0, $elapsed);
    }

    /**
     * The happy path: `status` already reports `COMPLETED` on the very
     * first read, so {@see WpaCliBackend::awaitAssociation()} never sleeps
     * at all.
     */
    #[Test]
    public function connect_sleeps_zero_times_when_status_reports_completed_immediately(): void
    {
        $sleeps = [];
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend(
            $runner,
            sleep: function (int $microseconds) use (&$sleeps): void {
                $sleeps[] = $microseconds;
            },
        );

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $this->assertSame([], $sleeps);
    }

    // --- connect() -> requestAddress() ---------------------------------------

    #[Test]
    public function connect_asks_dhcpcd_for_an_address_when_dhcpcd_is_present_and_not_already_supervising(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => "/sbin/dhcpcd\n",
            'dhcpcd -U wlan0' => ['output' => '', 'exit' => 1],
            'dhcpcd -n wlan0' => "\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $this->assertCount(11, $runner->commands);
        $last = array_slice($runner->commands, -3);
        $this->assertEquals(new Command('which', ['dhcpcd'], ['PATH' => self::searchPath()]), $last[0]);
        $this->assertEquals(new Command('/sbin/dhcpcd', ['-U', 'wlan0']), $last[1]);
        $this->assertEquals(new Command('/sbin/dhcpcd', ['-n', 'wlan0']), $last[2]);
    }

    #[Test]
    public function connect_leaves_an_already_supervising_dhcpcd_alone(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => "/sbin/dhcpcd\n",
            'dhcpcd -U wlan0' => "reason=BOUND\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        // which wpa_cli, list_networks, add_network, set_network (ssid, key_mgmt),
        // select_network, status, save_config, "which dhcpcd", "dhcpcd -U wlan0" — nothing beyond that.
        $this->assertCount(10, $runner->commands);
        $last = array_slice($runner->commands, -2);
        $this->assertEquals(new Command('which', ['dhcpcd'], ['PATH' => self::searchPath()]), $last[0]);
        $this->assertEquals(new Command('/sbin/dhcpcd', ['-U', 'wlan0']), $last[1]);

        foreach ($runner->commands as $command) {
            $this->assertFalse($command->program === '/sbin/dhcpcd' && in_array('-n', $command->arguments, true));
        }
    }

    #[Test]
    public function connect_asks_udhcpc_for_an_address_when_only_udhcpc_is_present(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => ['output' => '', 'exit' => 1],
            'which udhcpc' => "/sbin/udhcpc\n",
            'udhcpc -i wlan0 -n -q' => "\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $this->assertEquals(new Command('/sbin/udhcpc', ['-i', 'wlan0', '-n', '-q']), $runner->last());
    }

    #[Test]
    public function connect_asks_dhclient_for_an_address_when_only_dhclient_is_present(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => ['output' => '', 'exit' => 1],
            'which udhcpc' => ['output' => '', 'exit' => 1],
            'which dhclient' => "/sbin/dhclient\n",
            'dhclient -1 wlan0' => "\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $this->assertEquals(new Command('/sbin/dhclient', ['-1', 'wlan0']), $runner->last());
    }

    #[Test]
    public function connect_still_succeeds_and_records_no_dhcp_command_when_no_client_is_present(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        foreach ($runner->commands as $command) {
            $this->assertNotContains($command->program, ['dhcpcd', 'udhcpc', 'dhclient']);
        }
    }

    #[Test]
    public function connect_throws_command_failed_when_the_dhcp_client_exits_non_zero(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => "/sbin/dhcpcd\n",
            'dhcpcd -U wlan0' => ['output' => '', 'exit' => 1],
            'dhcpcd -n wlan0' => ['output' => '', 'exit' => 1, 'stderr' => "dhcpcd: no valid lease\n"],
        ]);
        $backend = new WpaCliBackend($runner);

        try {
            $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('dhcpcd', $exception->getMessage());
        }
    }

    // --- disconnect() -------------------------------------------------------

    #[Test]
    public function disconnect_sends_disconnect_as_an_argument(): void
    {
        $runner = self::runner(['disconnect' => "OK\n"]);
        $backend = new WpaCliBackend($runner);

        $backend->disconnect(new Device('wlan0'));

        $this->assertSame(self::WPA_CLI, $runner->last()->program);
        $this->assertSame(['-i', 'wlan0', 'disconnect'], $runner->last()->arguments);
        $this->assertNull($runner->last()->stdin);
    }

    // --- knownNetworks() ----------------------------------------------------

    #[Test]
    public function known_networks_returns_the_three_fixture_entries(): void
    {
        $runner = self::runner(['list_networks' => self::FIXTURES . '/wpacli/ListNetworks.txt']);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $networks = $backend->knownNetworks();

        $this->assertCount(3, $networks);
        $this->assertSame(self::WPA_CLI, $runner->last()->program);
        $this->assertSame(['-i', 'wlan0', 'list_networks'], $runner->last()->arguments);
    }

    // --- forget() -------------------------------------------------------------

    #[Test]
    public function forget_removes_the_network_it_finds_by_ssid(): void
    {
        $runner = self::runner([
            'list_networks' => self::FIXTURES . '/wpacli/ListNetworks.txt',
            'remove_network' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->forget('OldNet');

        $this->assertSame(['list_networks', 'remove_network 2', 'save_config'], self::wpaCliCalls($runner));
    }

    #[Test]
    public function forget_removes_every_block_for_a_duplicated_ssid(): void
    {
        $runner = self::runner([
            'list_networks' => "network id / ssid / bssid / flags\n0\tHomeNet\tany\t\n1\tOther\tany\t\n4\tHomeNet\tany\t[CURRENT]\n",
            'remove_network' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->forget('HomeNet');

        $this->assertSame(
            ['list_networks', 'remove_network 0', 'remove_network 4', 'save_config'],
            self::wpaCliCalls($runner),
        );
    }

    /** list_networks prints a non-ASCII SSID printf-escaped; forget() must still find it. */
    #[Test]
    public function forget_finds_an_ssid_that_list_networks_prints_escaped(): void
    {
        $runner = self::runner([
            'list_networks' => "network id / ssid / bssid / flags\n0\tCaf\\xc3\\xa9\tany\t\n",
            'remove_network' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->forget('Café');

        $this->assertSame(['list_networks', 'remove_network 0', 'save_config'], self::wpaCliCalls($runner));
    }

    #[Test]
    public function forget_throws_network_not_found_and_issues_no_removal_when_the_ssid_is_unknown(): void
    {
        $runner = self::runner(['list_networks' => self::FIXTURES . '/wpacli/ListNetworks.txt']);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->expectException(NetworkNotFound::class);

        try {
            $backend->forget('Nope');
        } finally {
            foreach ($runner->commands as $command) {
                $this->assertStringNotContainsString('remove_network', (string) $command->stdin);
            }
        }
    }

    // --- failure mapping ------------------------------------------------------

    #[Test]
    public function a_plain_fail_reply_becomes_command_failed(): void
    {
        $runner = self::runner(['disconnect' => "FAIL\n"]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        try {
            $backend->disconnect(new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertNotInstanceOf(PermissionDenied::class, $exception);
        }
    }

    #[Test]
    public function a_missing_control_interface_reply_becomes_command_failed_naming_it(): void
    {
        $runner = self::runner([
            'disconnect' => "Failed to connect to non-global ctrl_ifname: wlan0  error: No such file or directory\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        try {
            $backend->disconnect(new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertNotInstanceOf(PermissionDenied::class, $exception);
            $this->assertStringContainsString('ctrl_ifname', $exception->getMessage());
        }
    }

    #[Test]
    public function a_permission_denied_reply_names_the_netdev_group_not_polkit(): void
    {
        $runner = self::runner([
            'disconnect' => [
                'output' => '',
                'exit' => 0,
                'stderr' => "Failed to connect to wpa_supplicant - wpa_ctrl_open: Permission denied\n",
            ],
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        try {
            $backend->disconnect(new Device('wlan0'));
            $this->fail('Expected PermissionDenied to be thrown.');
        } catch (PermissionDenied $exception) {
            $this->assertStringContainsString('netdev', $exception->getMessage());
            $this->assertStringNotContainsString('polkit', $exception->getMessage());
        }
    }

    /**
     * M10: the `set_network …/psk …` script is the one command in this
     * backend whose stdin carries the secret ({@see Command::$stdinIsSecret}
     * is true on it — see {@see WpaCliBackend::connect()}). If `wpa_cli`
     * ever echoed something derived from it back, that text must never
     * reach a {@see CommandFailed} message: the exit code and the (already
     * masked) command are kept, the captured stdout/stderr is not — see
     * {@see \Sanchescom\WiFi\Exception\CommandFailed::fromResult()}. Covers
     * the non-zero-exit shape.
     */
    #[Test]
    public function a_failing_secret_script_never_lets_the_childs_output_into_the_message_on_a_nonzero_exit(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => [
                'output' => "hunter2 leaked back\n",
                'exit' => 1,
                'stderr' => '',
            ],
        ]);
        $backend = new WpaCliBackend($runner);

        try {
            $backend->connect('BELL340', Credentials::password('hunter2'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringNotContainsString('hunter2', $exception->getMessage());
            $this->assertStringNotContainsString('leaked back', $exception->getMessage());
            $this->assertStringContainsString('exited with 1', $exception->getMessage());
            $this->assertStringContainsString("<<< '***'", $exception->getMessage());
        }
    }

    /**
     * M10, the `despiteZeroExit()` shape: `wpa_cli` can reply `FAIL` (or
     * similar) while itself exiting 0 — {@see
     * WpaCliBackend::looksLikeWpaCliFailure()} — and the same rule applies:
     * a secret script's captured output must not reach the message.
     */
    #[Test]
    public function a_failing_secret_script_never_lets_the_childs_output_into_the_message_despite_zero_exit(): void
    {
        $runner = self::runner([
            'list_networks' => '',
            'add_network' => "0\n",
            'set_network' => "FAIL\nhunter2 echoed back\n",
        ]);
        $backend = new WpaCliBackend($runner);

        try {
            $backend->connect('BELL340', Credentials::password('hunter2'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringNotContainsString('hunter2', $exception->getMessage());
            $this->assertStringNotContainsString('echoed back', $exception->getMessage());
            $this->assertStringContainsString('exited 0 but reported failure', $exception->getMessage());
            $this->assertStringContainsString("<<< '***'", $exception->getMessage());
        }
    }

    // --- startHotspot() -------------------------------------------------------

    #[Test]
    public function start_hotspot_records_the_full_sequence_and_deletes_the_config_file_afterwards(): void
    {
        $runner = self::runner([
            'disconnect' => "OK\n",
            self::IP . ' addr flush dev wlan0' => '',
            self::IP . ' addr add 10.42.0.1/24 dev wlan0' => '',
            self::IP . ' link set wlan0 up' => '',
            self::HOSTAPD . ' -B -P' => '',
            self::DNSMASQ . ' --interface=wlan0' => '',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $hotspot = $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));

        $this->assertEquals(new Hotspot('hostapd', 'femus-setup', new Device('wlan0')), $hotspot);
        $this->assertCount(10, $runner->commands);

        [
            $whichWpaCli, $disconnect,
            $whichIp, $flush, $add, $up,
            $whichHostapd, $hostapd,
            $whichDnsmasq, $dnsmasq,
        ] = $runner->commands;

        $this->assertEquals(new Command('which', ['wpa_cli'], ['PATH' => self::searchPath()]), $whichWpaCli);
        $this->assertSame(self::WPA_CLI, $disconnect->program);
        $this->assertSame(['-i', 'wlan0', 'disconnect'], $disconnect->arguments);

        $this->assertEquals(new Command('which', ['ip'], ['PATH' => self::searchPath()]), $whichIp);
        $this->assertSame(self::IP, $flush->program);
        $this->assertSame(['addr', 'flush', 'dev', 'wlan0'], $flush->arguments);

        $this->assertSame(self::IP, $add->program);
        $this->assertSame(['addr', 'add', '10.42.0.1/24', 'dev', 'wlan0'], $add->arguments);

        $this->assertSame(self::IP, $up->program);
        $this->assertSame(['link', 'set', 'wlan0', 'up'], $up->arguments);

        $this->assertEquals(new Command('which', ['hostapd'], ['PATH' => self::searchPath()]), $whichHostapd);
        $this->assertSame(self::HOSTAPD, $hostapd->program);
        $this->assertSame('-B', $hostapd->arguments[0]);
        $this->assertSame('-P', $hostapd->arguments[1]);
        $this->assertSame(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, $hostapd->arguments[2]);

        $confFile = $hostapd->arguments[3];
        $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($confFile)));
        $this->assertStringStartsWith('php-wifi-hostapd-', basename($confFile));
        $this->assertFileDoesNotExist($confFile);

        $this->assertEquals(new Command('which', ['dnsmasq'], ['PATH' => self::searchPath()]), $whichDnsmasq);
        $this->assertSame(self::DNSMASQ, $dnsmasq->program);
        $this->assertSame(
            [
                '--interface=wlan0',
                '--bind-interfaces',
                '--except-interface=lo',
                '--dhcp-range=10.42.0.10,10.42.0.100,12h',
                '--pid-file=' . sys_get_temp_dir() . self::DNSMASQ_PID_FILE,
            ],
            $dnsmasq->arguments,
        );

        // The point of this whole guard: the passphrase reaches only the config file.
        foreach ($runner->commands as $command) {
            foreach ($command->arguments as $argument) {
                $this->assertStringNotContainsString('password1', $argument);
            }
            $this->assertStringNotContainsString('password1', (string) $command->stdin);
        }
    }

    #[Test]
    public function start_hotspot_never_starts_dnsmasq_and_still_deletes_the_config_when_hostapd_fails(): void
    {
        $runner = self::runner([
            'disconnect' => "OK\n",
            self::IP . ' addr flush dev wlan0' => '',
            self::IP . ' addr add 10.42.0.1/24 dev wlan0' => '',
            self::IP . ' link set wlan0 up' => '',
            self::HOSTAPD . ' -B -P' => [
                'output' => '',
                'exit' => 1,
                'stderr' => "Could not set interface wlan0 to master mode\n",
            ],
            self::DNSMASQ . ' --interface=wlan0' => '',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $confFile = null;

        try {
            $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $confFile = $exception->command->arguments[3] ?? null;
        }

        $this->assertIsString($confFile);
        $this->assertFileDoesNotExist($confFile);

        foreach ($runner->commands as $command) {
            $this->assertNotSame(self::DNSMASQ, $command->program);
        }
    }

    /**
     * C2: `hostapd -B` has already daemonised and written its pid file by
     * the time `dnsmasq` is asked to start; a `dnsmasq` that fails to start
     * (here, port 53 already bound — the ordinary real-hardware cause) must
     * not leave that `hostapd` running with nothing able to stop it. The pid
     * file is pre-seeded here exactly as the real `hostapd -B -P` would have
     * left it — the fake runner never writes one itself — with a `ps`
     * fixture confirming it names `hostapd`, so the rollback's `kill` can be
     * observed.
     */
    #[Test]
    public function start_hotspot_kills_the_already_started_hostapd_and_still_deletes_the_config_when_dnsmasq_fails(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");

        $runner = self::runner([
            'disconnect' => "OK\n",
            self::IP . ' addr flush dev wlan0' => '',
            self::IP . ' addr add 10.42.0.1/24 dev wlan0' => '',
            self::IP . ' link set wlan0 up' => '',
            self::HOSTAPD . ' -B -P' => '',
            self::DNSMASQ . ' --interface=wlan0' => [
                'output' => '',
                'exit' => 1,
                'stderr' => "dnsmasq: failed to create listening socket for port 53: Address already in use\n",
            ],
            'ps -p 111' => "hostapd\n",
            'kill 111' => '',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        try {
            $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('dnsmasq', $exception->getMessage());
        }

        $hostapdCommand = null;
        foreach ($runner->commands as $command) {
            if ($command->program === self::HOSTAPD) {
                $hostapdCommand = $command;
            }
        }
        $this->assertNotNull($hostapdCommand, 'hostapd was never started.');
        $this->assertFileDoesNotExist($hostapdCommand->arguments[3]);

        $killCommands = array_values(array_filter(
            $runner->commands,
            static fn (Command $command): bool => $command->program === 'kill',
        ));
        $this->assertCount(1, $killCommands);
        $this->assertSame(['111'], $killCommands[0]->arguments);

        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);
    }

    /**
     * `hostapd -B` writes its pid file from the daemonised child, which can
     * land after the parent returned and `dnsmasq` already failed. The
     * rollback must wait for it rather than find nothing to kill, then give
     * the interface back (address flushed, `reconnect`).
     */
    #[Test]
    public function start_hotspot_rollback_waits_for_a_late_hostapd_pid_file_and_releases_the_interface(): void
    {
        $pidFile = sys_get_temp_dir() . self::HOSTAPD_PID_FILE;
        $sleeps = 0;
        $runner = self::runner([
            'disconnect' => "OK\n",
            self::IP . ' addr flush dev wlan0' => '',
            self::IP . ' addr add 10.42.0.1/24 dev wlan0' => '',
            self::IP . ' link set wlan0 up' => '',
            self::HOSTAPD . ' -B -P' => '',
            self::DNSMASQ . ' --interface=wlan0' => ['output' => '', 'exit' => 2, 'stderr' => "dnsmasq: Address already in use\n"],
            'ps -p 111' => "hostapd\n",
            'kill 111' => '',
            'reconnect' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0', sleep: static function () use (&$sleeps, $pidFile): void {
            if (++$sleeps === 3) {
                file_put_contents($pidFile, "111\n");
            }
        });

        try {
            $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('dnsmasq', $exception->getMessage());
        }

        $tail = array_map(
            static fn (Command $command): string => $command->describe(),
            array_slice($runner->commands, -4),
        );
        $this->assertSame(
            ['ps -p 111 -o comm=', 'kill 111', self::IP . ' addr flush dev wlan0', self::WPA_CLI . ' -i wlan0 reconnect'],
            $tail,
        );
        $this->assertFileDoesNotExist($pidFile);
    }

    #[Test]
    public function start_hotspot_throws_a_clear_error_when_hostapd_cannot_be_resolved(): void
    {
        $runner = self::runner([
            'disconnect' => "OK\n",
            self::IP . ' addr flush dev wlan0' => '',
            self::IP . ' addr add 10.42.0.1/24 dev wlan0' => '',
            self::IP . ' link set wlan0 up' => '',
            'which hostapd' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        try {
            $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('hostapd', $exception->getMessage());
            $this->assertStringContainsString('not found', $exception->getMessage());
        }

        foreach ($runner->commands as $command) {
            $this->assertNotSame(self::DNSMASQ, $command->program);
        }
    }

    // --- startHotspot() refuses a concurrent second start ---------------------

    #[Test]
    public function start_hotspot_refuses_a_second_start_while_one_is_already_running(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = new FakeCommandRunner([
            'ps -p 111' => "hostapd\n",
            'ps -p 222' => "dnsmasq\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        try {
            $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('already running', $exception->getMessage());
            $this->assertStringContainsString(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, $exception->getMessage());
            $this->assertStringContainsString(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, $exception->getMessage());
        }

        // Nothing that would start a second daemon ran, and no tool was even
        // resolved; only the liveness probe (ps) used to decide a hotspot was
        // already active did.
        foreach ($runner->commands as $command) {
            $this->assertNotContains($command->program, ['which', 'wpa_cli', 'ip', 'hostapd', 'dnsmasq']);
        }

        // The pid files belong to the still-running first hotspot: untouched.
        $this->assertFileExists(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);
        $this->assertFileExists(sys_get_temp_dir() . self::DNSMASQ_PID_FILE);
    }

    // --- isHotspotActive() ------------------------------------------------------

    #[Test]
    public function is_hotspot_active_is_true_when_both_pid_files_hold_live_pids_naming_the_right_binary(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = new FakeCommandRunner([
            'ps -p 111' => "hostapd\n",
            'ps -p 222' => "dnsmasq\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->assertTrue($backend->isHotspotActive());
    }

    #[Test]
    public function is_hotspot_active_is_false_when_the_dnsmasq_pid_file_is_missing(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");

        $runner = new FakeCommandRunner(['ps -p 111' => "hostapd\n"]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->assertFalse($backend->isHotspotActive());
    }

    #[Test]
    public function is_hotspot_active_is_false_when_a_pid_is_dead(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = new FakeCommandRunner([
            'ps -p 111' => "hostapd\n",
            'ps -p 222' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->assertFalse($backend->isHotspotActive());
    }

    /**
     * A crashed daemon can leave its pid file behind; if the kernel later
     * reuses that number for an unrelated process (here, `vim`), the pid is
     * alive but it is not ours — this must read as "not running", not
     * "alive", and the stale file must not keep fooling future checks.
     */
    #[Test]
    public function is_hotspot_active_is_false_and_removes_the_stale_pid_file_when_the_pid_names_a_different_process(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = new FakeCommandRunner([
            'ps -p 111' => "vim\n",
            'ps -p 222' => "dnsmasq\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->assertFalse($backend->isHotspotActive());
        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);

        foreach ($runner->commands as $command) {
            $this->assertNotSame('kill', $command->program);
        }
    }

    #[Test]
    public function a_fresh_backend_instance_discovers_a_hotspot_started_by_another_instance(): void
    {
        $firstRunner = self::runner([
            'disconnect' => "OK\n",
            self::IP . ' addr flush dev wlan0' => '',
            self::IP . ' addr add 10.42.0.1/24 dev wlan0' => '',
            self::IP . ' link set wlan0 up' => '',
            self::HOSTAPD . ' -B -P' => '',
            self::DNSMASQ . ' --interface=wlan0' => '',
        ]);
        $firstBackend = new WpaCliBackend($firstRunner, 'wlan0');
        $firstBackend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));

        // The pid files hostapd/dnsmasq would have written themselves; the fake
        // runner does not, so the test stands in for the daemons.
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $secondRunner = new FakeCommandRunner([
            'ps -p 111' => "hostapd\n",
            'ps -p 222' => "dnsmasq\n",
        ]);
        $secondBackend = new WpaCliBackend($secondRunner, 'wlan0');

        $this->assertTrue($secondBackend->isHotspotActive());
    }

    // --- stopHotspot() ----------------------------------------------------------

    #[Test]
    public function stop_hotspot_terminates_both_pids_flushes_the_address_and_reconnects(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = self::runner([
            'ps -p 111' => "hostapd\n",
            'ps -p 222' => "dnsmasq\n",
            'kill 111' => '',
            'kill 222' => '',
            self::IP . ' addr flush dev wlan0' => '',
            'reconnect' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->stopHotspot();

        $programs = array_map(static fn (Command $command): string => $command->program, $runner->commands);
        $this->assertSame(
            ['ps', 'kill', 'ps', 'kill', 'which', self::IP, 'which', self::WPA_CLI],
            $programs,
        );

        $this->assertSame(['111'], $runner->commands[1]->arguments);
        $this->assertSame(['222'], $runner->commands[3]->arguments);
        $this->assertSame(['addr', 'flush', 'dev', 'wlan0'], $runner->commands[5]->arguments);
        $this->assertSame(['-i', 'wlan0', 'reconnect'], $runner->commands[7]->arguments);

        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::DNSMASQ_PID_FILE);
    }

    /**
     * The pid a pid file names may belong to an unrelated process (see the
     * `isHotspotActive()` "stale pid file" test above); `stopHotspot()`
     * must apply the same check before ever sending a signal, so it never
     * kills a stranger — while still cleaning up the now-useless pid file.
     */
    #[Test]
    public function stop_hotspot_does_not_signal_a_process_that_does_not_match_the_expected_binary(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = self::runner([
            'ps -p 111' => "vim\n",
            'ps -p 222' => "dnsmasq\n",
            'kill 222' => '',
            self::IP . ' addr flush dev wlan0' => '',
            'reconnect' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->stopHotspot();

        $killed = array_values(array_map(
            static fn (Command $command): array => $command->arguments,
            array_filter($runner->commands, static fn (Command $command): bool => $command->program === 'kill'),
        ));
        $this->assertSame([['222']], $killed);

        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::DNSMASQ_PID_FILE);
    }

    #[Test]
    public function stop_hotspot_is_harmless_to_call_twice(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = self::runner([
            'ps -p 111' => "hostapd\n",
            'ps -p 222' => "dnsmasq\n",
            'kill 111' => '',
            'kill 222' => '',
            self::IP . ' addr flush dev wlan0' => '',
            'reconnect' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->stopHotspot();
        $backend->stopHotspot();

        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::DNSMASQ_PID_FILE);

        // ip and wpa_cli are each resolved once, even across two stopHotspot() calls.
        $whichCalls = array_filter(
            $runner->commands,
            static fn (Command $command): bool => $command->program === 'which',
        );
        $this->assertCount(2, $whichCalls);
    }

    /**
     * The hardware defect this fix addresses: with no interface configured
     * (the real-world `BackendFactory` construction — see
     * {@see self::the_configured_interface_short_circuits_detect_device()}),
     * `stopHotspot()` must resolve the interface through `detectDevice()`,
     * and `iw dev` reports `wlan0` as `type AP` while hostapd is running —
     * there is no `managed` interface to find. Before this fix,
     * `detectDevice()` returned nothing and `stopHotspot()` threw
     * `DeviceNotFound`, leaving the hotspot permanently unstoppable.
     */
    #[Test]
    public function stop_hotspot_resolves_the_interface_through_detect_device_while_the_radio_is_in_ap_mode(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = self::runner([
            self::IW . ' dev' => self::FIXTURES . '/iw/DevApOnly.txt',
            'ps -p 111' => "hostapd\n",
            'ps -p 222' => "dnsmasq\n",
            'kill 111' => '',
            'kill 222' => '',
            self::IP . ' addr flush dev wlan0' => '',
            'reconnect' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->stopHotspot();

        $this->assertSame(self::IW, $runner->commands[1]->program);
        $this->assertSame(['dev'], $runner->commands[1]->arguments);
        $this->assertSame(['addr', 'flush', 'dev', 'wlan0'], $runner->commands[7]->arguments);
        $this->assertSame(['-i', 'wlan0', 'reconnect'], $runner->commands[9]->arguments);

        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::DNSMASQ_PID_FILE);
    }

    #[Test]
    public function stop_hotspot_tolerates_missing_pid_files(): void
    {
        $runner = self::runner([
            self::IP . ' addr flush dev wlan0' => '',
            'reconnect' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->stopHotspot();

        foreach ($runner->commands as $command) {
            $this->assertNotSame('kill', $command->program);
        }
    }

    /**
     * I6: "no wpa_supplicant is running" is routine on a hostapd-only box —
     * both daemons are already dead and the address already flushed by the
     * time `reconnect` runs, so a failing final `reconnect` must not make
     * `stopHotspot()` itself throw.
     */
    #[Test]
    public function stop_hotspot_does_not_throw_when_the_final_reconnect_fails(): void
    {
        $runner = self::runner([
            self::IP . ' addr flush dev wlan0' => '',
            'reconnect' => [
                'output' => '',
                'exit' => 1,
                'stderr' => "Failed to connect to non-global ctrl_ifname: wlan0  error: No such file or directory\n",
            ],
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->stopHotspot();

        $this->assertSame(['-i', 'wlan0', 'reconnect'], $runner->last()->arguments);
    }

    /**
     * The wpa_cli calls in order, without the leading `-i <iface>`; a script
     * sent on stdin shows as `<stdin>`.
     *
     * @return list<string>
     */
    private static function wpaCliCalls(FakeCommandRunner $runner): array
    {
        return array_values(array_map(
            static fn (Command $command): string => $command->stdin !== null
                ? '<stdin>'
                : implode(' ', array_slice($command->arguments, 2)),
            array_filter($runner->commands, static fn (Command $command): bool => $command->program === self::WPA_CLI),
        ));
    }

    private static function searchPath(): string
    {
        return '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin';
    }

    private static function unescapeWpaSupplicantString(string $quoted): string
    {
        $inner = substr($quoted, 1, -1);
        $result = '';

        for ($i = 0, $length = strlen($inner); $i < $length; $i++) {
            if ($inner[$i] === '\\' && $i + 1 < $length) {
                $result .= $inner[$i + 1];
                $i++;
                continue;
            }

            $result .= $inner[$i];
        }

        return $result;
    }
}
