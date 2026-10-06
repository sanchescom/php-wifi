<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\NmcliBackend;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\Hotspot;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Value\NetworkCollection;

final class NmcliBackendTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/linux';

    private function backend(): NmcliBackend
    {
        return new NmcliBackend($this->runner());
    }

    private function runner(): FakeCommandRunner
    {
        return new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            'connection show' => self::FIXTURES . '/Connections.txt',
            '-f NAME,UUID,TYPE connection show' => self::FIXTURES . '/Profiles.txt',
            'connection show BELL340' => "BELL340\n",
            'connection show Cafe: Corner' => "Cafe Corner Wifi\n",
            'connection show Hotspot' => "femus-setup\n",
            'connection delete' => '',
            'connection down' => '',
            'connection add' => '',
            'connection up' => '',
            'device wifi connect' => '',
            'device disconnect' => '',
            'device wifi list' => self::FIXTURES . '/Networks.txt',
            '-f DEVICE,TYPE device' => self::FIXTURES . '/Devices.txt',
        ]);
    }

    #[Test]
    public function scan_lists_networks_via_the_exact_nmcli_command(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $networks = $backend->scan();

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertCount(6, $networks);

        $expected = new Command(
            'nmcli',
            [
                '--terse',
                '--fields',
                'active,ssid,bssid,mode,chan,freq,signal,security,wpa-flags,rsn-flags',
                'device',
                'wifi',
                'list',
            ],
            ['LANG' => 'C'],
        );

        $this->assertSame($expected->describe(), $runner->last()->describe());
        $this->assertSame($expected->env, $runner->last()->env);
    }

    #[Test]
    public function scan_returns_an_empty_collection_when_nmcli_lists_nothing(): void
    {
        $runner = new FakeCommandRunner(['device wifi list' => '']);
        $backend = new NmcliBackend($runner);

        $networks = $backend->scan();

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertSame(0, $networks->count());
        $this->assertTrue($networks->isEmpty());
    }

    #[Test]
    public function connect_puts_the_passphrase_on_stdin_instead_of_argv(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->connect('Home', Credentials::password('p w'), new Device('wlan0'));

        $command = $runner->last();

        $this->assertSame(
            ['-w', '10', '--ask', 'device', 'wifi', 'connect', 'Home', 'ifname', 'wlan0'],
            $command->arguments,
        );
        $this->assertSame([], $command->secretIndexes);
        $this->assertSame('p w' . "\n", $command->stdin);
        $this->assertTrue($command->stdinIsSecret);
        $this->assertSame(['LANG' => 'C'], $command->env);

        foreach ($runner->commands as $recorded) {
            foreach ($recorded->arguments as $argument) {
                $this->assertStringNotContainsString('p w', $argument);
                $this->assertStringNotContainsString('password', $argument);
            }
        }
    }

    /**
     * `--ask` only prompts when nmcli holds no secret of its own for the
     * SSID already; a saved profile with a stale passphrase would otherwise
     * make nmcli silently reuse it and ignore a freshly typed, corrected
     * one. So a passphrase-carrying connect() first deletes any existing
     * profile for the SSID, then runs the `--ask` connect — in exactly that
     * order, and the passphrase appears in no argument of either command.
     */
    #[Test]
    public function connect_with_a_passphrase_deletes_any_saved_profile_first(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->connect('Home', Credentials::password('p w'), new Device('wlan0'));

        $this->assertCount(3, $runner->commands);

        $this->assertSame(['-t', '-f', 'NAME,UUID,TYPE', 'connection', 'show'], $runner->commands[0]->arguments);

        // The VPN that is also called "Home" is not a Wi-Fi profile and stays.
        $delete = $runner->commands[1];
        $this->assertSame(
            ['connection', 'delete', 'uuid', '11111111-1111-1111-1111-111111111111'],
            $delete->arguments,
        );
        $this->assertSame(['LANG' => 'C'], $delete->env);
        $this->assertNull($delete->stdin);

        $connect = $runner->commands[2];
        $this->assertSame(
            ['-w', '10', '--ask', 'device', 'wifi', 'connect', 'Home', 'ifname', 'wlan0'],
            $connect->arguments,
        );

        foreach ($runner->commands as $recorded) {
            foreach ($recorded->arguments as $argument) {
                $this->assertStringNotContainsString('p w', $argument);
            }
        }
    }

    /**
     * Neither the profile listing nor the delete may fail connect(): their
     * results are never turned into an exception.
     */
    #[Test]
    public function connect_ignores_a_profile_delete_that_fails(): void
    {
        $runner = new FakeCommandRunner([
            'connection show' => self::FIXTURES . '/Profiles.txt',
            'connection delete' => [
                'output' => '',
                'exit' => 10,
                'stderr' => 'Error: unknown connection.',
            ],
            'device wifi connect' => '',
        ]);
        $backend = new NmcliBackend($runner);

        $backend->connect('Home', Credentials::password('p w'), new Device('wlan0'));

        $this->assertCount(3, $runner->commands);
        $this->assertSame(['-w', '10', '--ask', 'device', 'wifi', 'connect', 'Home', 'ifname', 'wlan0'], $runner->last()->arguments);
    }

    #[Test]
    public function connect_ignores_a_profile_listing_that_fails(): void
    {
        $runner = new FakeCommandRunner([
            'connection show' => ['output' => '', 'exit' => 8, 'stderr' => 'Error: NetworkManager is not running.'],
            'device wifi connect' => '',
        ]);
        $backend = new NmcliBackend($runner);

        $backend->connect('Home', Credentials::password('p w'), new Device('wlan0'));

        $this->assertCount(2, $runner->commands);
        $this->assertSame('connect', $runner->last()->arguments[5]);
    }

    #[Test]
    public function connect_with_a_passphrase_masks_it_in_the_displayed_command(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->connect('Home', Credentials::password('hunter2'), new Device('wlan0'));

        $display = $runner->last()->toDisplay(Os::Linux);

        $this->assertStringContainsString('***', $display);
        $this->assertStringNotContainsString('hunter2', $display);
    }

    #[Test]
    public function connect_without_credentials_omits_ask_and_stdin(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->connect('Home', Credentials::none(), new Device('wlan0'));

        $this->assertCount(1, $runner->commands);
        $command = $runner->last();

        $this->assertSame(
            ['-w', '10', 'device', 'wifi', 'connect', 'Home', 'ifname', 'wlan0'],
            $command->arguments,
        );
        $this->assertSame([], $command->secretIndexes);
        $this->assertNull($command->stdin);
        $this->assertFalse($command->stdinIsSecret);
    }

    #[Test]
    public function disconnect_runs_the_exact_nmcli_command(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->disconnect(new Device('wlan0'));

        $this->assertSame(['device', 'disconnect', 'wlan0'], $runner->last()->arguments);
        $this->assertSame(['LANG' => 'C'], $runner->last()->env);
    }

    #[Test]
    public function detect_device_returns_the_first_wifi_device(): void
    {
        $backend = $this->backend();

        $device = $backend->detectDevice();

        $this->assertEquals(new Device('wlan0'), $device);
    }

    #[Test]
    public function detect_device_throws_when_no_wifi_device_exists(): void
    {
        $runner = new FakeCommandRunner([
            '-f DEVICE,TYPE device' => "eth0:ethernet:connected\nlo:loopback:connected (externally)\n",
        ]);
        $backend = new NmcliBackend($runner);

        $this->expectException(DeviceNotFound::class);

        $backend->detectDevice();
    }

    #[Test]
    public function known_networks_returns_only_wireless_connections(): void
    {
        $backend = $this->backend();

        $networks = $backend->knownNetworks();

        $this->assertCount(3, $networks);
    }

    #[Test]
    public function known_networks_resolve_their_real_ssid(): void
    {
        $runner = $this->runner();
        $networks = (new NmcliBackend($runner))->knownNetworks();

        $this->assertCount(3, $networks);
        $this->assertSame('Hotspot', $networks[2]->name);
        $this->assertSame('femus-setup', $networks[2]->ssid);
        $this->assertCount(4, $runner->commands); // one list + three lookups
    }

    #[Test]
    public function a_failing_ssid_lookup_falls_back_to_the_connection_name(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            'connection show' => self::FIXTURES . '/Connections.txt',
            'connection show BELL340' => "BELL340\n",
            'connection show Cafe: Corner' => "Cafe Corner Wifi\n",
            'connection show Hotspot' => [
                'output' => '',
                'exit' => 10,
                'stderr' => 'Error: unknown connection.',
            ],
        ]);

        $networks = (new NmcliBackend($runner))->knownNetworks();

        $this->assertSame('Hotspot', $networks[2]->name);
        $this->assertSame('Hotspot', $networks[2]->ssid);
    }

    #[Test]
    public function a_permission_denied_ssid_lookup_propagates(): void
    {
        $runner = new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            'connection show' => self::FIXTURES . '/Connections.txt',
            'connection show BELL340' => "BELL340\n",
            'connection show Cafe: Corner' => "Cafe Corner Wifi\n",
            'connection show Hotspot' => [
                'output' => '',
                'exit' => 4,
                'stderr' => 'Error: Not authorized to control networking.',
            ],
        ]);

        $this->expectException(PermissionDenied::class);

        (new NmcliBackend($runner))->knownNetworks();
    }

    #[Test]
    public function forget_deletes_the_wifi_profile_by_its_uuid(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->forget('Cafe: Corner');

        $this->assertSame(
            ['connection', 'delete', 'uuid', '33333333-3333-3333-3333-333333333333'],
            $runner->last()->arguments,
        );
    }

    /** Deleting by name would take the VPN called "Home" along with the Wi-Fi profile. */
    #[Test]
    public function forget_leaves_a_profile_of_another_type_with_the_same_name_alone(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->forget('Home');

        $deletes = array_values(array_filter(
            $runner->commands,
            static fn (Command $command): bool => ($command->arguments[1] ?? null) === 'delete',
        ));
        $this->assertCount(1, $deletes);
        $this->assertSame(
            ['connection', 'delete', 'uuid', '11111111-1111-1111-1111-111111111111'],
            $deletes[0]->arguments,
        );
    }

    /** Saved networks are not scanned for, so the message must not mention a scan. */
    #[Test]
    public function forget_of_a_network_that_is_not_saved_says_so(): void
    {
        $backend = $this->backend();

        try {
            $backend->forget('Wired connection 1');
            $this->fail('Expected NetworkNotFound to be thrown.');
        } catch (NetworkNotFound $exception) {
            $this->assertSame('No saved network named "Wired connection 1".', $exception->getMessage());
        }
    }

    /**
     * @param list<Command> $commands
     * @return list<string> the arguments of the one `connection add` among $commands
     */
    private function addArguments(array $commands): array
    {
        $adds = array_values(array_filter(
            $commands,
            static fn (Command $command): bool => ($command->arguments[1] ?? null) === 'add',
        ));
        $this->assertCount(1, $adds);

        return $adds[0]->arguments;
    }

    /**
     * `nmcli device wifi hotspot` takes the passphrase as an argument. The
     * hotspot is raised without it instead: a profile with no passphrase,
     * then an activation that is handed the passphrase on stdin.
     */
    #[Test]
    public function start_hotspot_adds_a_profile_without_the_passphrase_and_activates_it_with_the_passphrase_on_stdin(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $hotspot = $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));

        $this->assertSame(
            [
                'connection', 'add', 'type', 'wifi', 'ifname', 'wlan0', 'con-name', 'Hotspot', 'autoconnect', 'no',
                'ssid', 'femus-setup', '--', 'wifi.mode', 'ap', 'ipv4.method', 'shared', 'ipv6.method', 'ignore',
                'wifi-sec.key-mgmt', 'wpa-psk',
            ],
            $this->addArguments($runner->commands),
        );

        $up = $runner->last();
        $this->assertSame(['-w', '20', '--ask', 'connection', 'up', 'Hotspot'], $up->arguments);
        $this->assertSame("password1\n", $up->stdin);
        $this->assertTrue($up->stdinIsSecret);

        foreach ($runner->commands as $command) {
            foreach ($command->arguments as $argument) {
                $this->assertStringNotContainsString('password1', $argument);
            }
        }

        $this->assertEquals(new Hotspot('Hotspot', 'femus-setup', new Device('wlan0')), $hotspot);
    }

    #[Test]
    public function start_hotspot_with_5ghz_band(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->startHotspot(new HotspotConfig('femus-setup', 'password1', Band::GHz5), new Device('wlan0'));

        $this->assertSame(
            ['wifi.mode', 'ap', 'wifi.band', 'a', 'ipv4.method'],
            array_slice($this->addArguments($runner->commands), 13, 5),
        );
    }

    #[Test]
    public function start_hotspot_with_2_4ghz_band(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->startHotspot(new HotspotConfig('femus-setup', 'password1', Band::GHz2_4), new Device('wlan0'));

        $this->assertSame(
            ['wifi.mode', 'ap', 'wifi.band', 'bg', 'ipv4.method'],
            array_slice($this->addArguments($runner->commands), 13, 5),
        );
    }

    /** nmcli refuses a channel that comes without a band, so the band the channel belongs to goes with it. */
    #[Test]
    public function start_hotspot_with_a_channel_passes_it_together_with_its_band(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->startHotspot(new HotspotConfig('femus-setup', 'password1', channel: 11), new Device('wlan0'));

        $this->assertSame(
            ['wifi.mode', 'ap', 'wifi.band', 'bg', 'wifi.channel', '11', 'ipv4.method'],
            array_slice($this->addArguments($runner->commands), 13, 7),
        );
    }

    /** One "Hotspot" profile however often a hotspot is started — and never somebody's VPN of that name. */
    #[Test]
    public function start_hotspot_first_deletes_an_earlier_hotspot_profile_by_its_uuid(): void
    {
        $runner = new FakeCommandRunner([
            'connection show' => "Hotspot:aaaaaaaa-0000-0000-0000-000000000001:802-11-wireless\n"
                . "Hotspot:aaaaaaaa-0000-0000-0000-000000000002:vpn\n",
            'connection delete' => '',
            'connection add' => '',
            'connection up' => '',
        ]);
        $backend = new NmcliBackend($runner);

        $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));

        $this->assertSame(
            [
                'nmcli -t -f NAME,UUID,TYPE connection show',
                'nmcli connection delete uuid aaaaaaaa-0000-0000-0000-000000000001',
            ],
            array_map(static fn (Command $command): string => $command->describe(), array_slice($runner->commands, 0, 2)),
        );
        $this->assertSame('add', $runner->commands[2]->arguments[1]);
    }

    /** A profile that could not be activated is of no use and used to pile up, one per refused call. */
    #[Test]
    public function start_hotspot_removes_the_profile_it_added_when_the_activation_fails(): void
    {
        $runner = new FakeCommandRunner([
            'connection show' => ['', "Hotspot:aaaaaaaa-0000-0000-0000-000000000003:802-11-wireless\n"],
            'connection delete' => '',
            'connection add' => '',
            'connection up' => ['output' => '', 'exit' => 4, 'stderr' => 'Error: Connection activation failed.'],
        ]);
        $backend = new NmcliBackend($runner);

        try {
            $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertStringNotContainsString('password1', $exception->getMessage());
        }

        $this->assertSame(
            'nmcli connection delete uuid aaaaaaaa-0000-0000-0000-000000000003',
            $runner->last()->describe(),
        );
    }

    /** NetworkManager has nowhere to put a country; ignoring it would leave the caller believing it was set. */
    #[Test]
    public function start_hotspot_with_a_country_is_refused_before_any_command(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        try {
            $backend->startHotspot(
                new HotspotConfig('femus-setup', 'password1', country: 'CA'),
                new Device('wlan0'),
            );
            $this->fail('Expected UnsupportedOperation to be thrown.');
        } catch (UnsupportedOperation $exception) {
            $this->assertStringContainsString('iw reg set CA', $exception->getMessage());
            $this->assertSame([], $runner->commands);
        }
    }

    #[Test]
    public function start_hotspot_on_6ghz_is_unsupported(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $this->expectException(UnsupportedOperation::class);

        $backend->startHotspot(
            new HotspotConfig('femus-setup', 'password1', Band::GHz6),
            new Device('wlan0'),
        );
    }

    #[Test]
    public function start_hotspot_on_6ghz_runs_no_command_before_rejecting(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        try {
            $backend->startHotspot(
                new HotspotConfig('femus-setup', 'password1', Band::GHz6),
                new Device('wlan0'),
            );
            $this->fail('Expected UnsupportedOperation to be thrown.');
        } catch (UnsupportedOperation) {
            $this->assertSame([], $runner->commands);
        }
    }

    #[Test]
    public function stop_hotspot_runs_the_exact_nmcli_command(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->stopHotspot();

        $this->assertSame(['connection', 'down', 'Hotspot'], $runner->last()->arguments);
    }

    #[Test]
    public function is_hotspot_active_is_true_when_the_hotspot_connection_is_active(): void
    {
        $backend = $this->backend();

        $this->assertTrue($backend->isHotspotActive());
    }

    #[Test]
    public function is_hotspot_active_is_false_when_no_hotspot_connection_is_active(): void
    {
        $runner = new FakeCommandRunner(['connection show --active' => '']);
        $backend = new NmcliBackend($runner);

        $this->assertFalse($backend->isHotspotActive());
    }

    #[Test]
    public function connect_throws_permission_denied_when_nmcli_refuses(): void
    {
        $runner = new FakeCommandRunner([
            'connect' => [
                'output' => '',
                'exit' => 4,
                'stderr' => 'Error: Not authorized to control networking.',
            ],
        ]);
        $backend = new NmcliBackend($runner);

        $this->expectException(PermissionDenied::class);

        $backend->connect('Home', Credentials::password('hunter2'), new Device('wlan0'));
    }

    #[Test]
    public function connect_throws_command_failed_masking_the_passphrase_on_other_failures(): void
    {
        $runner = new FakeCommandRunner([
            'connect' => [
                'output' => '',
                'exit' => 4,
                'stderr' => 'Error: Connection activation failed',
            ],
        ]);
        $backend = new NmcliBackend($runner);

        try {
            $backend->connect('Home', Credentials::password('hunter2'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $e) {
            $this->assertStringContainsString('***', $e->getMessage());
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }
}
