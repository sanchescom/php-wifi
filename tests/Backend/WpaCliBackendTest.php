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
use Sanchescom\WiFi\Value\NetworkCollection;

final class WpaCliBackendTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures';

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
        ]);
        $backend = new WpaCliBackend($runner);

        $backend->connect('BELL340', Credentials::password('p w'), new Device('wlan0'));

        $this->assertCount(3, $runner->commands);

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
