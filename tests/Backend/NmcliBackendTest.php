<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\NmcliBackend;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Shell\Command;
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
            'connection show BELL340' => "BELL340\n",
            'connection show Cafe: Corner' => "Cafe Corner Wifi\n",
            'connection show Hotspot' => "femus-setup\n",
            'connection delete' => '',
            'connection down' => '',
            'device wifi hotspot' => '',
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
    public function connect_passes_the_password_as_a_secret_argument(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->connect('Home', Credentials::password('p w'), new Device('wlan0'));

        $command = $runner->last();

        $this->assertSame(
            ['-w', '10', 'device', 'wifi', 'connect', 'Home', 'password', 'p w', 'ifname', 'wlan0'],
            $command->arguments,
        );
        $this->assertSame([7], $command->secretIndexes);
        $this->assertSame(['LANG' => 'C'], $command->env);
    }

    #[Test]
    public function connect_without_credentials_omits_the_password_pair(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->connect('Home', Credentials::none(), new Device('wlan0'));

        $command = $runner->last();

        $this->assertSame(
            ['-w', '10', 'device', 'wifi', 'connect', 'Home', 'ifname', 'wlan0'],
            $command->arguments,
        );
        $this->assertSame([], $command->secretIndexes);
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
    public function forget_deletes_the_connection_by_name(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->forget('Cafe: Corner');

        $this->assertSame(['connection', 'delete', 'Cafe: Corner'], $runner->last()->arguments);
    }

    #[Test]
    public function start_hotspot_with_5ghz_band(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $hotspot = $backend->startHotspot(
            new HotspotConfig('femus-setup', 'password1', Band::GHz5),
            new Device('wlan0'),
        );

        $command = $runner->last();

        $this->assertSame(
            ['device', 'wifi', 'hotspot', 'ifname', 'wlan0', 'ssid', 'femus-setup', 'password', 'password1', 'band', 'a'],
            $command->arguments,
        );
        $this->assertSame([8], $command->secretIndexes);
        $this->assertEquals(new Hotspot('Hotspot', 'femus-setup', new Device('wlan0')), $hotspot);
    }

    #[Test]
    public function start_hotspot_with_2_4ghz_band(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->startHotspot(
            new HotspotConfig('femus-setup', 'password1', Band::GHz2_4),
            new Device('wlan0'),
        );

        $this->assertSame(
            ['device', 'wifi', 'hotspot', 'ifname', 'wlan0', 'ssid', 'femus-setup', 'password', 'password1', 'band', 'bg'],
            $runner->last()->arguments,
        );
    }

    #[Test]
    public function start_hotspot_without_a_band_omits_the_band_pair(): void
    {
        $runner = $this->runner();
        $backend = new NmcliBackend($runner);

        $backend->startHotspot(
            new HotspotConfig('femus-setup', 'password1'),
            new Device('wlan0'),
        );

        $this->assertSame(
            ['device', 'wifi', 'hotspot', 'ifname', 'wlan0', 'ssid', 'femus-setup', 'password', 'password1'],
            $runner->last()->arguments,
        );
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
    public function connect_throws_command_failed_masking_the_password_on_other_failures(): void
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
