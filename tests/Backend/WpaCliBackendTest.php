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
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->scan();

        foreach ($runner->commands as $command) {
            $this->assertNotSame(self::IW, $command->program);
        }
    }

    // --- scan() ---------------------------------------------------------------

    #[Test]
    public function scan_triggers_a_scan_then_reads_scan_results(): void
    {
        $runner = self::runner([
            'scan_results' => self::FIXTURES . '/wpacli/ScanResults.txt',
            'scan' => '',
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $networks = $backend->scan();

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertCount(4, $networks);

        $this->assertCount(3, $runner->commands);
        $this->assertEquals(new Command('which', ['wpa_cli'], ['PATH' => self::searchPath()]), $runner->commands[0]);
        $this->assertEquals(
            new Command(self::WPA_CLI, ['-i', 'wlan0'], ['LANG' => 'C'], [], "scan\nquit\n"),
            $runner->commands[1],
        );
        $this->assertEquals(
            new Command(self::WPA_CLI, ['-i', 'wlan0'], ['LANG' => 'C'], [], "scan_results\nquit\n"),
            $runner->commands[2],
        );
        $this->assertSame(self::WPA_CLI, $runner->commands[1]->program);
        $this->assertSame(self::WPA_CLI, $runner->commands[2]->program);
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

    // --- connect() --------------------------------------------------------

    #[Test]
    public function connect_with_a_password_never_puts_it_in_argv(): void
    {
        $runner = self::runner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::password('p w'), new Device('wlan0'));

        $this->assertCount(7, $runner->commands);

        $addNetwork = $runner->commands[1];
        $this->assertSame(self::WPA_CLI, $addNetwork->program);
        $this->assertSame(['-i', 'wlan0'], $addNetwork->arguments);
        $this->assertSame("add_network\nquit\n", $addNetwork->stdin);
        $this->assertFalse($addNetwork->stdinIsSecret);

        $script = $runner->commands[2];
        $this->assertSame(self::WPA_CLI, $script->program);
        $this->assertSame(['-i', 'wlan0'], $script->arguments);
        $this->assertSame(
            "set_network 0 ssid \"BELL340\"\nset_network 0 psk \"p w\"\nenable_network 0\nsave_config\nquit\n",
            $script->stdin,
        );
        $this->assertTrue($script->stdinIsSecret);

        $status = $runner->commands[3];
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
        $runner = self::runner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $script = $runner->commands[2];
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
        $runner = self::runner([
            'add_network' => "3\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which' => ['output' => '', 'exit' => 1],
        ]);
        $backend = new WpaCliBackend($runner);

        $ssid = "He said \"hi\"\\";

        $backend->connect($ssid, Credentials::none(), new Device('wlan0'));

        $script = $runner->commands[2];
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
        $runner = self::runner(['add_network' => "FAIL\n"]);
        $backend = new WpaCliBackend($runner);

        try {
            $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed) {
            // which wpa_cli, then the failing add_network attempt.
            $this->assertCount(2, $runner->commands);
        }
    }

    #[Test]
    public function connect_throws_command_failed_naming_the_last_state_when_association_never_completes(): void
    {
        $runner = self::runner([
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
        $runner = self::runner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => "/sbin/dhcpcd\n",
            'dhcpcd -U wlan0' => ['output' => '', 'exit' => 1],
            'dhcpcd -n wlan0' => "\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        $this->assertCount(7, $runner->commands);
        $last = array_slice($runner->commands, -3);
        $this->assertEquals(new Command('which', ['dhcpcd'], ['PATH' => self::searchPath()]), $last[0]);
        $this->assertEquals(new Command('/sbin/dhcpcd', ['-U', 'wlan0']), $last[1]);
        $this->assertEquals(new Command('/sbin/dhcpcd', ['-n', 'wlan0']), $last[2]);
    }

    #[Test]
    public function connect_leaves_an_already_supervising_dhcpcd_alone(): void
    {
        $runner = self::runner([
            'add_network' => "0\n",
            'set_network' => "OK\n",
            'status' => self::FIXTURES . '/wpacli/Status.txt',
            'which dhcpcd' => "/sbin/dhcpcd\n",
            'dhcpcd -U wlan0' => "reason=BOUND\n",
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::none(), new Device('wlan0'));

        // which wpa_cli, add_network, set_network script, status, "which
        // dhcpcd", "dhcpcd -U wlan0" — nothing beyond that.
        $this->assertCount(6, $runner->commands);
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
    public function disconnect_sends_disconnect_over_stdin(): void
    {
        $runner = self::runner(['disconnect' => "OK\n"]);
        $backend = new WpaCliBackend($runner);

        $backend->disconnect(new Device('wlan0'));

        $this->assertSame(self::WPA_CLI, $runner->last()->program);
        $this->assertSame(['-i', 'wlan0'], $runner->last()->arguments);
        $this->assertSame("disconnect\nquit\n", $runner->last()->stdin);
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
        $this->assertSame("list_networks\nquit\n", $runner->last()->stdin);
    }

    // --- forget() -------------------------------------------------------------

    #[Test]
    public function forget_removes_the_network_it_finds_by_ssid(): void
    {
        $runner = self::runner([
            'list_networks' => self::FIXTURES . '/wpacli/ListNetworks.txt',
            'remove_network' => "OK\nOK\n",
        ]);
        $backend = new WpaCliBackend($runner, 'wlan0');

        $backend->forget('OldNet');

        // which wpa_cli, list_networks, remove_network+save_config.
        $this->assertCount(3, $runner->commands);
        $this->assertSame("remove_network 2\nsave_config\nquit\n", $runner->last()->stdin);
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
        $this->assertSame(['-i', 'wlan0'], $disconnect->arguments);
        $this->assertSame("disconnect\nquit\n", $disconnect->stdin);

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
        $this->assertSame("reconnect\nquit\n", $runner->commands[7]->stdin);

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
