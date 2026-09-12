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
use Sanchescom\WiFi\Shell\ShellCommandRunner;
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

    // --- detectDevice() -----------------------------------------------------

    #[Test]
    public function detect_device_returns_the_first_wireless_interface(): void
    {
        $runner = new FakeCommandRunner(['iw dev' => self::FIXTURES . '/iw/Dev.txt']);
        $backend = new WpaCliBackend($runner);

        $device = $backend->detectDevice();

        $this->assertEquals(new Device('wlan0'), $device);
        $this->assertSame('iw dev', $runner->last()->describe());
        $this->assertNull($runner->last()->stdin);
    }

    #[Test]
    public function detect_device_throws_when_iw_dev_lists_no_wireless_interface(): void
    {
        $runner = new FakeCommandRunner(['iw dev' => "phy#0\n"]);
        $backend = new WpaCliBackend($runner);

        $this->expectException(DeviceNotFound::class);

        $backend->detectDevice();
    }

    #[Test]
    public function the_configured_interface_short_circuits_detect_device(): void
    {
        $runner = new FakeCommandRunner(['scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt', 'scan' => '']);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->scan();

        foreach ($runner->commands as $command) {
            $this->assertNotSame('iw', $command->program);
        }
    }

    // --- scan() ---------------------------------------------------------------

    #[Test]
    public function scan_triggers_a_scan_then_reads_scan_results(): void
    {
        $runner = new FakeCommandRunner([
            'scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt',
            'scan' => '',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $networks = $backend->scan();

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertCount(4, $networks);

        $this->assertCount(2, $runner->commands);
        $this->assertEquals(
            new Command('wpa_cli', ['-i', 'wlan0'], ['LANG' => 'C'], [], "scan\nquit\n"),
            $runner->commands[0],
        );
        $this->assertEquals(
            new Command('wpa_cli', ['-i', 'wlan0'], ['LANG' => 'C'], [], "scan_results\nquit\n"),
            $runner->commands[1],
        );
    }

    // --- connect() --------------------------------------------------------

    #[Test]
    public function connect_with_a_password_never_puts_it_in_argv(): void
    {
        $runner = new FakeCommandRunner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::password('p w'), new Device('wlan0'));

        $this->assertCount(6, $runner->commands);

        $addNetwork = $runner->commands[0];
        $this->assertSame(['-i', 'wlan0'], $addNetwork->arguments);
        $this->assertSame("add_network\nquit\n", $addNetwork->stdin);
        $this->assertFalse($addNetwork->stdinIsSecret);

        $script = $runner->commands[1];
        $this->assertSame(['-i', 'wlan0'], $script->arguments);
        $this->assertSame(
            "set_network 0 ssid \"BELL340\"\nset_network 0 psk \"p w\"\nenable_network 0\nsave_config\nquit\n",
            $script->stdin,
        );
        $this->assertTrue($script->stdinIsSecret);

        $status = $runner->commands[2];
        $this->assertSame("status\nquit\n", $status->stdin);

        // The point of this whole task: the passphrase lives only in stdin.
        $this->assertStringContainsString('p w', $script->stdin ?? '');
        foreach ($runner->commands as $command) {
            foreach ($command->arguments as $argument) {
                $this->assertStringNotContainsString('p w', $argument);
            }
        }
    }

    #[Test]
    public function connect_without_credentials_uses_key_mgmt_none_and_is_not_secret(): void
    {
        $runner = new FakeCommandRunner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $script = $runner->commands[1];
        $this->assertSame(
            "set_network 0 ssid \"BELL340\"\nset_network 0 key_mgmt NONE\nenable_network 0\nsave_config\nquit\n",
            $script->stdin,
        );
        $this->assertFalse($script->stdinIsSecret);
        $this->assertStringNotContainsString('psk', $script->stdin);
    }

    #[Test]
    public function connect_quotes_an_ssid_containing_quotes_and_a_backslash_so_it_round_trips(): void
    {
        $runner = new FakeCommandRunner([
            'add_network' => "3\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $ssid = "He said \"hi\"\\";

        $backend->connect($ssid, Credentials::none(), new Device('wlan0'));

        $script = $runner->commands[1];
        $this->assertNotNull($script->stdin);

        $ssidLine = null;
        foreach (explode("\n", $script->stdin) as $line) {
            if (str_starts_with($line, 'set_network 3 ssid ')) {
                $ssidLine = $line;
            }
        }

        $this->assertNotNull($ssidLine, 'no "set_network 3 ssid ..." line was found in: ' . $script->stdin);

        $quoted = substr($ssidLine, strlen('set_network 3 ssid '));
        $this->assertStringStartsWith('"', $quoted);
        $this->assertStringEndsWith('"', $quoted);
        $this->assertSame($ssid, self::unescapeWpaSupplicantString($quoted));
    }

    #[Test]
    public function connect_throws_command_failed_when_add_network_replies_fail(): void
    {
        $runner = new FakeCommandRunner(['add_network' => "FAIL\n"]);
        $backend = new WpaCliBackend($runner);

        try {
            $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed) {
            $this->assertCount(1, $runner->commands);
        }
    }

    #[Test]
    public function connect_throws_command_failed_naming_the_last_state_when_association_never_completes(): void
    {
        $runner = new FakeCommandRunner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => "wpa_state=SCANNING\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0', 3);

        try {
            $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringContainsString('SCANNING', $exception->getMessage());
        }

        $statusCommands = array_filter(
            $runner->commands,
            static fn (Command $command): bool => $command->stdin === "status\nquit\n",
        );
        $this->assertCount(3, $statusCommands);
    }

    // --- connect() -> requestAddress() ---------------------------------------

    #[Test]
    public function connect_asks_dhcpcd_for_an_address_when_dhcpcd_is_present_and_not_already_supervising(): void
    {
        $runner = new FakeCommandRunner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => "/sbin/dhcpcd\n",
            'dhcpcd -U wlan0' => ['output' => '', 'exit' => 1],
            'dhcpcd -n wlan0' => "\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $this->assertCount(6, $runner->commands);
        $last = array_slice($runner->commands, -3);
        $this->assertEquals(new Command('which', ['dhcpcd']), $last[0]);
        $this->assertEquals(new Command('dhcpcd', ['-U', 'wlan0']), $last[1]);
        $this->assertEquals(new Command('dhcpcd', ['-n', 'wlan0']), $last[2]);
    }

    #[Test]
    public function connect_leaves_an_already_supervising_dhcpcd_alone(): void
    {
        $runner = new FakeCommandRunner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => "/sbin/dhcpcd\n",
            'dhcpcd -U wlan0' => "reason=BOUND\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        // add_network, set_network script, status, "which dhcpcd", "dhcpcd -U wlan0" — nothing beyond that.
        $this->assertCount(5, $runner->commands);
        $last = array_slice($runner->commands, -2);
        $this->assertEquals(new Command('which', ['dhcpcd']), $last[0]);
        $this->assertEquals(new Command('dhcpcd', ['-U', 'wlan0']), $last[1]);

        foreach ($runner->commands as $command) {
            $this->assertFalse($command->program === 'dhcpcd' && in_array('-n', $command->arguments, true));
        }
    }

    #[Test]
    public function connect_asks_udhcpc_for_an_address_when_only_udhcpc_is_present(): void
    {
        $runner = new FakeCommandRunner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => ['output' => '', 'exit' => 1],
            'which udhcpc' => "/sbin/udhcpc\n",
            'udhcpc -i wlan0 -n -q' => "\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $this->assertEquals(new Command('udhcpc', ['-i', 'wlan0', '-n', '-q']), $runner->last());
    }

    #[Test]
    public function connect_asks_dhclient_for_an_address_when_only_dhclient_is_present(): void
    {
        $runner = new FakeCommandRunner([
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

        $this->assertEquals(new Command('dhclient', ['-1', 'wlan0']), $runner->last());
    }

    #[Test]
    public function connect_still_succeeds_and_records_no_dhcp_command_when_no_client_is_present(): void
    {
        $runner = new FakeCommandRunner([
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
        $runner = new FakeCommandRunner([
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

    // --- requestAddress() probe mechanism (real process, no fake runner) ----

    /**
     * Every other test in this file drives {@see FakeCommandRunner}, which
     * never execs anything — nothing there could have caught the bug fixed
     * alongside this test (probing with the `command` shell builtin, which
     * Debian and Raspberry Pi OS ship no binary for, so every probe silently
     * exec'd nothing and reported absent). This proves the actual mechanism
     * {@see WpaCliBackend::commandExists()} relies on — `which <name>`
     * through a real {@see ShellCommandRunner} — reports present/absent
     * correctly on this machine, for a real process, no fixture involved.
     */
    #[Test]
    public function which_reports_a_binary_that_certainly_exists_as_present(): void
    {
        $result = ShellCommandRunner::forCurrentOs()->run(new Command('which', ['ls']));

        $this->assertTrue($result->isSuccessful());
    }

    #[Test]
    public function which_reports_a_binary_that_certainly_does_not_exist_as_absent(): void
    {
        $result = ShellCommandRunner::forCurrentOs()->run(new Command('which', ['php-wifi-definitely-not-a-binary']));

        $this->assertFalse($result->isSuccessful());
    }

    // --- disconnect() -------------------------------------------------------

    #[Test]
    public function disconnect_sends_disconnect_over_stdin(): void
    {
        $runner = new FakeCommandRunner(['disconnect' => "OK\n"]);
        $backend = new WpaCliBackend($runner);

        $backend->disconnect(new Device('wlan0'));

        $this->assertSame(['-i', 'wlan0'], $runner->last()->arguments);
        $this->assertSame("disconnect\nquit\n", $runner->last()->stdin);
    }

    // --- knownNetworks() ----------------------------------------------------

    #[Test]
    public function known_networks_returns_the_three_fixture_entries(): void
    {
        $runner = new FakeCommandRunner(['list_networks' => self::FIXTURES . '/wpacli/ListNetworks.txt']);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $networks = $backend->knownNetworks();

        $this->assertCount(3, $networks);
        $this->assertSame("list_networks\nquit\n", $runner->last()->stdin);
    }

    // --- forget() -------------------------------------------------------------

    #[Test]
    public function forget_removes_the_network_it_finds_by_ssid(): void
    {
        $runner = new FakeCommandRunner([
            'list_networks' => self::FIXTURES . '/wpacli/ListNetworks.txt',
            'remove_network' => "OK\nOK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->forget('OldNet');

        $this->assertCount(2, $runner->commands);
        $this->assertSame("remove_network 2\nsave_config\nquit\n", $runner->last()->stdin);
    }

    #[Test]
    public function forget_throws_network_not_found_and_issues_no_removal_when_the_ssid_is_unknown(): void
    {
        $runner = new FakeCommandRunner(['list_networks' => self::FIXTURES . '/wpacli/ListNetworks.txt']);
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
        $runner = new FakeCommandRunner(['disconnect' => "FAIL\n"]);
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
        $runner = new FakeCommandRunner([
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
        $runner = new FakeCommandRunner([
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

    // --- startHotspot() -------------------------------------------------------

    #[Test]
    public function start_hotspot_records_the_full_sequence_and_deletes_the_config_file_afterwards(): void
    {
        $runner = new FakeCommandRunner([
            'disconnect' => "OK\n",
            'ip addr flush dev wlan0' => '',
            'ip addr add 10.42.0.1/24 dev wlan0' => '',
            'ip link set wlan0 up' => '',
            'hostapd -B -P' => '',
            'dnsmasq --interface=wlan0' => '',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $hotspot = $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));

        $this->assertEquals(new Hotspot('hostapd', 'femus-setup', new Device('wlan0')), $hotspot);
        $this->assertCount(6, $runner->commands);

        [$disconnect, $flush, $add, $up, $hostapd, $dnsmasq] = $runner->commands;

        $this->assertSame('wpa_cli', $disconnect->program);
        $this->assertSame(['-i', 'wlan0'], $disconnect->arguments);
        $this->assertSame("disconnect\nquit\n", $disconnect->stdin);

        $this->assertSame('ip', $flush->program);
        $this->assertSame(['addr', 'flush', 'dev', 'wlan0'], $flush->arguments);

        $this->assertSame('ip', $add->program);
        $this->assertSame(['addr', 'add', '10.42.0.1/24', 'dev', 'wlan0'], $add->arguments);

        $this->assertSame('ip', $up->program);
        $this->assertSame(['link', 'set', 'wlan0', 'up'], $up->arguments);

        $this->assertSame('hostapd', $hostapd->program);
        $this->assertSame('-B', $hostapd->arguments[0]);
        $this->assertSame('-P', $hostapd->arguments[1]);
        $this->assertSame(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, $hostapd->arguments[2]);

        $confFile = $hostapd->arguments[3];
        $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($confFile)));
        $this->assertStringStartsWith('php-wifi-hostapd-', basename($confFile));
        $this->assertFileDoesNotExist($confFile);

        $this->assertSame('dnsmasq', $dnsmasq->program);
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
        $runner = new FakeCommandRunner([
            'disconnect' => "OK\n",
            'ip addr flush dev wlan0' => '',
            'ip addr add 10.42.0.1/24 dev wlan0' => '',
            'ip link set wlan0 up' => '',
            'hostapd -B -P' => [
                'output' => '',
                'exit' => 1,
                'stderr' => "Could not set interface wlan0 to master mode\n",
            ],
            'dnsmasq --interface=wlan0' => '',
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
            $this->assertNotSame('dnsmasq', $command->program);
        }
    }

    // --- isHotspotActive() ------------------------------------------------------

    #[Test]
    public function is_hotspot_active_is_true_when_both_pid_files_hold_live_pids(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = new FakeCommandRunner([
            'ps -p 111' => '',
            'ps -p 222' => '',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->assertTrue($backend->isHotspotActive());
    }

    #[Test]
    public function is_hotspot_active_is_false_when_the_dnsmasq_pid_file_is_missing(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");

        $runner = new FakeCommandRunner(['ps -p 111' => '']);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->assertFalse($backend->isHotspotActive());
    }

    #[Test]
    public function is_hotspot_active_is_false_when_a_pid_is_dead(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = new FakeCommandRunner([
            'ps -p 111' => '',
            'ps -p 222' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $this->assertFalse($backend->isHotspotActive());
    }

    #[Test]
    public function a_fresh_backend_instance_discovers_a_hotspot_started_by_another_instance(): void
    {
        $firstRunner = new FakeCommandRunner([
            'disconnect' => "OK\n",
            'ip addr flush dev wlan0' => '',
            'ip addr add 10.42.0.1/24 dev wlan0' => '',
            'ip link set wlan0 up' => '',
            'hostapd -B -P' => '',
            'dnsmasq --interface=wlan0' => '',
        ]);
        $firstBackend = new WpaCliBackend($firstRunner, 'wlan0');
        $firstBackend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));

        // The pid files hostapd/dnsmasq would have written themselves; the fake
        // runner does not, so the test stands in for the daemons.
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $secondRunner = new FakeCommandRunner([
            'ps -p 111' => '',
            'ps -p 222' => '',
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

        $runner = new FakeCommandRunner([
            'kill 111' => '',
            'kill 222' => '',
            'ip addr flush dev wlan0' => '',
            'reconnect' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->stopHotspot();

        $programs = array_map(static fn (Command $command): string => $command->program, $runner->commands);
        $this->assertSame(['kill', 'kill', 'ip', 'wpa_cli'], $programs);

        $this->assertSame(['111'], $runner->commands[0]->arguments);
        $this->assertSame(['222'], $runner->commands[1]->arguments);
        $this->assertSame(['addr', 'flush', 'dev', 'wlan0'], $runner->commands[2]->arguments);
        $this->assertSame("reconnect\nquit\n", $runner->commands[3]->stdin);

        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::DNSMASQ_PID_FILE);
    }

    #[Test]
    public function stop_hotspot_is_harmless_to_call_twice(): void
    {
        file_put_contents(sys_get_temp_dir() . self::HOSTAPD_PID_FILE, "111\n");
        file_put_contents(sys_get_temp_dir() . self::DNSMASQ_PID_FILE, "222\n");

        $runner = new FakeCommandRunner([
            'kill 111' => '',
            'kill 222' => '',
            'ip addr flush dev wlan0' => '',
            'reconnect' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->stopHotspot();
        $backend->stopHotspot();

        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::HOSTAPD_PID_FILE);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . self::DNSMASQ_PID_FILE);
    }

    #[Test]
    public function stop_hotspot_tolerates_missing_pid_files(): void
    {
        $runner = new FakeCommandRunner([
            'ip addr flush dev wlan0' => '',
            'reconnect' => "OK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->stopHotspot();

        foreach ($runner->commands as $command) {
            $this->assertNotSame('kill', $command->program);
        }
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
