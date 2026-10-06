<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sanchescom\WiFi\Backend\NmcliBackend;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Exception\NoAddress;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Exception\WrongPassphrase;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\CommandRunner;
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

    /**
     * stdout and exit code as `nmcli -w 10 --ask device wifi connect` gave
     * them on the Pi for a wrong passphrase: the secret is asked for a
     * second time, in different words, and then the wait runs out.
     */
    #[Test]
    public function connect_reports_a_wrong_passphrase(): void
    {
        $runner = new FakeCommandRunner([
            'connection show' => '',
            'device wifi connect' => [
                'output' => self::FIXTURES . '/ConnectWrongPassphrase.txt',
                'exit' => 3,
                'stderr' => "Error: Timeout 10 sec expired.\n",
            ],
        ]);
        $backend = new NmcliBackend($runner);

        try {
            $backend->connect('BELL340', Credentials::password('typo-typo'), new Device('wlan0'));
            $this->fail('Expected WrongPassphrase to be thrown.');
        } catch (WrongPassphrase $exception) {
            $this->assertSame('The passphrase for "BELL340" was not accepted.', $exception->getMessage());
            $this->assertSame(3, $exception->result->exitCode);
        }
    }

    /** A timeout alone says nothing about the passphrase: the first request for it is always printed. */
    #[Test]
    public function connect_that_times_out_without_a_second_request_for_the_secret_stays_a_plain_failure(): void
    {
        $runner = new FakeCommandRunner([
            'connection show' => '',
            'device wifi connect' => [
                'output' => "Push of the WPS button on the router or a password is required to access the wireless"
                    . " network 'BELL340'.\nPassword (802-11-wireless-security.psk): \n",
                'exit' => 3,
                'stderr' => "Error: Timeout 10 sec expired.\n",
            ],
        ]);
        $backend = new NmcliBackend($runner);

        try {
            $backend->connect('BELL340', Credentials::password('right-one'), new Device('wlan0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $exception) {
            $this->assertSame(CommandFailed::class, $exception::class);
        }
    }

    /** Reply and exit code as measured on the Pi. */
    #[Test]
    public function connect_reports_a_network_that_is_not_there(): void
    {
        $runner = new FakeCommandRunner([
            'device wifi connect' => [
                'output' => '',
                'exit' => 10,
                'stderr' => "Error: No network with SSID 'NoSuchNet20' found.\n",
            ],
        ]);
        $backend = new NmcliBackend($runner);

        $this->expectException(NetworkNotFound::class);
        $this->expectExceptionMessage('No network named "NoSuchNet20" was found in the scan.');

        $backend->connect('NoSuchNet20', Credentials::none(), new Device('wlan0'));
    }

    /** NetworkManager's wording for activation failure reason 5; not reproduced on hardware. */
    #[Test]
    public function connect_reports_no_address(): void
    {
        $runner = new FakeCommandRunner([
            'device wifi connect' => [
                'output' => '',
                'exit' => 4,
                'stderr' => 'Error: Connection activation failed: (5) IP configuration could not be reserved'
                    . " (no available address, timeout, etc.).\n",
            ],
        ]);
        $backend = new NmcliBackend($runner);

        $this->expectException(NoAddress::class);
        $this->expectExceptionMessage('Joined "Home", but the network gave the device no address.');

        $backend->connect('Home', Credentials::none(), new Device('wlan0'));
    }

    #[Test]
    public function connect_keeps_permission_denied_as_it_is(): void
    {
        $runner = new FakeCommandRunner([
            'device wifi connect' => [
                'output' => '',
                'exit' => 4,
                'stderr' => "Error: Failed to add/activate new connection: Not authorized to control networking.\n",
            ],
        ]);
        $backend = new NmcliBackend($runner);

        $this->expectException(PermissionDenied::class);

        $backend->connect('Home', Credentials::none(), new Device('wlan0'));
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

        $add = $this->addArguments($runner->commands);
        $uuid = $add[14];
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
        $this->assertSame(
            [
                'connection', 'add', 'type', 'wifi', 'ifname', 'wlan0', 'con-name', 'Hotspot', 'autoconnect', 'no',
                'ssid', 'femus-setup', '--', 'connection.uuid', $uuid, 'wifi.mode', 'ap',
                'ipv4.method', 'shared', 'ipv6.method', 'ignore',
                // WPA2/CCMP only: without these NetworkManager would also accept WPA and TKIP.
                'wifi-sec.key-mgmt', 'wpa-psk', 'wifi-sec.proto', 'rsn',
                'wifi-sec.pairwise', 'ccmp', 'wifi-sec.group', 'ccmp',
            ],
            $add,
        );

        // Activated by the UUID it was created with: a profile of another type may share the name.
        $up = $runner->last();
        $this->assertSame(['-w', '20', '--ask', 'connection', 'up', 'uuid', $uuid], $up->arguments);
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
            array_slice($this->addArguments($runner->commands), 15, 5),
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
            array_slice($this->addArguments($runner->commands), 15, 5),
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
            array_slice($this->addArguments($runner->commands), 15, 7),
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
            'connection show' => '',
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
            ['connection', 'delete', 'uuid', $this->addArguments($runner->commands)[14]],
            $runner->last()->arguments,
        );
    }

    /**
     * NetworkManager runs the hotspot's dnsmasq itself; the portal is one
     * line in a file that dnsmasq reads when it starts. Every other shared
     * connection's dnsmasq reads that directory too, so the file is there
     * while the hotspot is being activated and gone the moment it is up.
     */
    #[Test]
    public function a_captive_portal_is_a_dnsmasq_file_that_exists_only_while_the_hotspot_is_activated(): void
    {
        $directory = sys_get_temp_dir() . '/php-wifi-dnsmasq-test-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $file = $directory . '/php-wifi-captive.conf';

        $runner = new class ($this->runner(), $file) implements CommandRunner {
            /** @var array<string, string|false> what the file held as each nmcli subcommand ran */
            public array $seen = [];

            public function __construct(private readonly CommandRunner $inner, private readonly string $file)
            {
            }

            public function run(Command $command): CommandResult
            {
                foreach (['connection add', 'connection up', 'connection down'] as $step) {
                    if (str_contains($command->describe(), $step)) {
                        $this->seen[$step] = is_file($this->file) ? file_get_contents($this->file) : false;
                    }
                }

                return $this->inner->run($command);
            }
        };
        $backend = new NmcliBackend($runner, $directory);

        try {
            $backend->startHotspot(
                new HotspotConfig('femus-setup', 'password1', captivePortal: true),
                new Device('wlan0'),
            );

            $this->assertSame("address=/#/10.42.0.1\n", $runner->seen['connection up']);
            $this->assertFileDoesNotExist($file, 'the file must not outlive the activation');

            // A file a killed process left behind goes when the hotspot is stopped…
            file_put_contents($file, "address=/#/10.42.0.1\n");
            $backend->stopHotspot();
            $this->assertFileDoesNotExist($file);

            // …and a hotspot started without a portal must not inherit one.
            file_put_contents($file, "address=/#/10.42.0.1\n");
            $backend->startHotspot(new HotspotConfig('femus-setup', 'password1'), new Device('wlan0'));
            $this->assertFalse($runner->seen['connection up']);
        } finally {
            @unlink($file);
            rmdir($directory);
        }
    }

    /** A file that cannot be removed keeps hijacking DNS; that is reported, never swallowed. */
    #[Test]
    public function a_captive_portal_file_that_cannot_be_removed_is_an_error(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can remove a file from a read-only directory');
        }

        $directory = sys_get_temp_dir() . '/php-wifi-dnsmasq-test-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $file = $directory . '/php-wifi-captive.conf';
        file_put_contents($file, "address=/#/10.42.0.1\n");
        chmod($directory, 0500);
        $backend = new NmcliBackend($this->runner(), $directory);

        try {
            $backend->stopHotspot();
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('delete it by hand', $exception->getMessage());
        } finally {
            chmod($directory, 0700);
            unlink($file);
            rmdir($directory);
        }
    }

    #[Test]
    public function a_captive_portal_file_does_not_survive_a_hotspot_that_failed_to_start(): void
    {
        $directory = sys_get_temp_dir() . '/php-wifi-dnsmasq-test-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $runner = new FakeCommandRunner([
            'connection show' => '',
            'connection delete' => '',
            'connection add' => '',
            'connection up' => ['output' => '', 'exit' => 4, 'stderr' => 'Error: Connection activation failed.'],
        ]);
        $backend = new NmcliBackend($runner, $directory);

        try {
            $backend->startHotspot(
                new HotspotConfig('femus-setup', 'password1', captivePortal: true),
                new Device('wlan0'),
            );
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed) {
            $this->assertFileDoesNotExist($directory . '/php-wifi-captive.conf');
        } finally {
            rmdir($directory);
        }
    }

    #[Test]
    public function a_captive_portal_that_cannot_be_configured_is_refused_before_any_profile_is_added(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner, '/nonexistent/php-wifi-test');

        try {
            $backend->startHotspot(
                new HotspotConfig('femus-setup', 'password1', captivePortal: true),
                new Device('wlan0'),
            );
            $this->fail('Expected UnsupportedOperation to be thrown.');
        } catch (UnsupportedOperation $exception) {
            $this->assertStringContainsString('takes root', $exception->getMessage());
        }

        foreach ($runner->commands as $command) {
            $this->assertNotSame('add', $command->arguments[1] ?? null);
        }
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
