<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\BackendFactory;
use Sanchescom\WiFi\Backend\NetshBackend;
use Sanchescom\WiFi\Backend\NetworksetupBackend;
use Sanchescom\WiFi\Backend\NmcliBackend;
use Sanchescom\WiFi\Backend\SupportsHotspot;
use Sanchescom\WiFi\Backend\SupportsKnownNetworks;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Value\KnownNetwork;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\Security;
use Sanchescom\WiFi\WiFi;

final class WiFiTest extends TestCase
{
    private const LINUX_FIXTURES = __DIR__ . '/Fixtures/linux';
    private const DARWIN_FIXTURES = __DIR__ . '/Fixtures/darwin';

    private function linuxRunner(): FakeCommandRunner
    {
        return new FakeCommandRunner([
            'connection show --active' => "Hotspot\n",
            'connection show' => self::LINUX_FIXTURES . '/Connections.txt',
            'connection delete' => '',
            'connection down' => '',
            'device wifi hotspot' => '',
            'device wifi connect' => '',
            'device disconnect' => '',
            'device wifi list' => self::LINUX_FIXTURES . '/Networks.txt',
            '-f DEVICE,TYPE device' => self::LINUX_FIXTURES . '/Devices.txt',
        ]);
    }

    private function darwinRunner(): FakeCommandRunner
    {
        return new FakeCommandRunner([
            'SPAirPortDataType' => self::DARWIN_FIXTURES . '/SystemProfiler.txt',
            '-listallhardwareports' => self::DARWIN_FIXTURES . '/HardwarePorts.txt',
            '-setairportnetwork' => '',
            '-setairportpower' => '',
        ]);
    }

    private function runner(): FakeCommandRunner
    {
        return new FakeCommandRunner([
            '-f DEVICE,TYPE device' => self::LINUX_FIXTURES . '/Devices.txt',
            'device wifi connect' => '',
        ]);
    }

    private function sampleNetwork(string $ssid): Network
    {
        return $this->networkNamed($ssid, false);
    }

    private function hiddenNetwork(string $ssid): Network
    {
        return $this->networkNamed($ssid, true);
    }

    private function networkNamed(string $ssid, bool $ssidHidden): Network
    {
        return new Network(
            ssid: $ssid,
            ssidHidden: $ssidHidden,
            bssid: Bssid::from('00:11:22:33:44:55'),
            channel: 6,
            band: Band::GHz2_4,
            frequency: 2437,
            signal: null,
            security: Security::WPA2,
            securityFlags: '',
            connected: false,
        );
    }

    #[Test]
    public function connect_with_a_string_scans_detects_the_device_then_connects_in_order(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->connect('AlphaNet-foiEmE', Credentials::password('p'));

        $this->assertCount(3, $runner->commands);
        $this->assertStringContainsString('device wifi list', $runner->commands[0]->describe());
        $this->assertStringContainsString('-f DEVICE,TYPE device', $runner->commands[1]->describe());
        $this->assertStringContainsString('device wifi connect', $runner->commands[2]->describe());
    }

    #[Test]
    public function connect_with_an_unknown_ssid_throws_before_any_connect_command(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        try {
            $wifi->connect('nope', Credentials::password('p'));
            $this->fail('Expected NetworkNotFound to be thrown.');
        } catch (NetworkNotFound) {
            $this->assertCount(1, $runner->commands);
            $this->assertStringContainsString('device wifi list', $runner->commands[0]->describe());
        }
    }

    #[Test]
    public function connect_with_a_network_object_skips_the_scan(): void
    {
        $runner = new FakeCommandRunner([
            '-f DEVICE,TYPE device' => self::LINUX_FIXTURES . '/Devices.txt',
            'device wifi connect' => '',
        ]);
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->connect($this->sampleNetwork('AlphaNet-foiEmE'), Credentials::password('p'));

        $this->assertCount(2, $runner->commands);
        $this->assertStringContainsString('-f DEVICE,TYPE device', $runner->commands[0]->describe());
        $this->assertStringContainsString('device wifi connect', $runner->commands[1]->describe());
    }

    #[Test]
    public function connect_with_a_hidden_network_throws_before_any_command(): void
    {
        $runner = new FakeCommandRunner([]);
        $wifi = new WiFi(new NmcliBackend($runner));

        try {
            $wifi->connect($this->hiddenNetwork(''), Credentials::password('p'));
            $this->fail('Expected InvalidArgument to be thrown.');
        } catch (InvalidArgument) {
            $this->assertSame([], $runner->commands);
        }
    }

    #[Test]
    public function connect_with_a_redacted_network_throws_before_any_command(): void
    {
        $runner = new FakeCommandRunner([]);
        $wifi = new WiFi(new NmcliBackend($runner));

        try {
            $wifi->connect($this->hiddenNetwork('<redacted>'), Credentials::password('p'));
            $this->fail('Expected InvalidArgument to be thrown.');
        } catch (InvalidArgument) {
            $this->assertSame([], $runner->commands);
        }
    }

    #[Test]
    public function connect_with_an_empty_ssid_string_throws_before_the_scan(): void
    {
        $runner = new FakeCommandRunner([]);
        $wifi = new WiFi(new NmcliBackend($runner));

        try {
            $wifi->connect('', Credentials::password('p'));
            $this->fail('Expected InvalidArgument to be thrown.');
        } catch (InvalidArgument) {
            $this->assertSame([], $runner->commands);
        }
    }

    #[Test]
    public function connect_with_an_explicit_device_skips_device_detection(): void
    {
        $runner = new FakeCommandRunner([
            'device wifi connect' => '',
        ]);
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->connect($this->sampleNetwork('AlphaNet-foiEmE'), Credentials::password('p'), new Device('wlan0'));

        $this->assertCount(1, $runner->commands);
        $this->assertStringContainsString('device wifi connect', $runner->commands[0]->describe());
    }

    #[Test]
    public function connect_to_joins_without_scanning_first(): void
    {
        $runner = $this->runner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->connectTo('BELL340', Credentials::password('p w'), new Device('wlan0'));

        $this->assertCount(1, $runner->commands);
        $this->assertSame(
            ['-w', '10', 'device', 'wifi', 'connect', 'BELL340', 'password', 'p w', 'ifname', 'wlan0'],
            $runner->commands[0]->arguments,
        );
    }

    #[Test]
    public function connect_to_detects_the_device_when_none_is_given(): void
    {
        $runner = $this->runner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->connectTo('BELL340', Credentials::none());

        $this->assertCount(2, $runner->commands);
        $this->assertStringContainsString('-f DEVICE,TYPE device', $runner->commands[0]->describe());
        $this->assertStringContainsString('device wifi connect BELL340', $runner->commands[1]->describe());
    }

    #[Test]
    public function connect_to_rejects_an_empty_ssid(): void
    {
        $runner = $this->runner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $this->expectException(InvalidArgument::class);

        try {
            $wifi->connectTo('', Credentials::none());
        } finally {
            $this->assertSame([], $runner->commands);
        }
    }

    #[Test]
    public function disconnect_with_no_device_detects_it_first(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->disconnect();

        $this->assertCount(2, $runner->commands);
        $this->assertStringContainsString('-f DEVICE,TYPE device', $runner->commands[0]->describe());
        $this->assertStringContainsString('device disconnect', $runner->commands[1]->describe());
    }

    #[Test]
    public function disconnect_with_an_explicit_device_skips_detection(): void
    {
        $runner = new FakeCommandRunner([
            'device disconnect' => '',
        ]);
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->disconnect(new Device('wlan0'));

        $this->assertCount(1, $runner->commands);
        $this->assertStringContainsString('device disconnect', $runner->commands[0]->describe());
    }

    #[Test]
    public function device_delegates_to_detect_device(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $this->assertEquals(new Device('wlan0'), $wifi->device());
    }

    #[Test]
    public function known_networks_delegates_to_the_backend(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $this->assertCount(3, $wifi->knownNetworks());
    }

    #[Test]
    public function forget_with_a_known_network_passes_its_connection_name(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->forget(new KnownNetwork('Cafe: Corner', 'Cafe Corner Wifi', null, false));

        $this->assertSame(['connection', 'delete', 'Cafe: Corner'], $runner->last()->arguments);
    }

    #[Test]
    public function forget_with_a_string_passes_it_through(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->forget('Cafe: Corner');

        $this->assertSame(['connection', 'delete', 'Cafe: Corner'], $runner->last()->arguments);
    }

    #[Test]
    public function start_hotspot_without_a_device_detects_it_first(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $hotspot = $wifi->startHotspot(new HotspotConfig('femus-setup', 'password1'));

        $this->assertCount(2, $runner->commands);
        $this->assertStringContainsString('-f DEVICE,TYPE device', $runner->commands[0]->describe());
        $this->assertStringContainsString('device wifi hotspot', $runner->commands[1]->describe());
        $this->assertSame('femus-setup', $hotspot->ssid);
    }

    #[Test]
    public function start_hotspot_with_a_device_on_the_config_skips_detection(): void
    {
        $runner = new FakeCommandRunner(['device wifi hotspot' => '']);
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->startHotspot(new HotspotConfig('femus-setup', 'password1', device: new Device('wlan0')));

        $this->assertCount(1, $runner->commands);
        $this->assertStringContainsString('device wifi hotspot', $runner->commands[0]->describe());
    }

    #[Test]
    public function stop_hotspot_delegates_to_the_backend(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $wifi->stopHotspot();

        $this->assertSame(['connection', 'down', 'Hotspot'], $runner->last()->arguments);
    }

    #[Test]
    public function is_hotspot_active_delegates_to_the_backend(): void
    {
        $runner = $this->linuxRunner();
        $wifi = new WiFi(new NmcliBackend($runner));

        $this->assertTrue($wifi->isHotspotActive());
    }

    #[Test]
    public function supports_reflects_the_backends_interfaces(): void
    {
        $wifi = new WiFi(new NmcliBackend($this->linuxRunner()));

        $this->assertTrue($wifi->supports(SupportsKnownNetworks::class));
        $this->assertTrue($wifi->supports(SupportsHotspot::class));
    }

    #[Test]
    public function backend_returns_the_underlying_backend(): void
    {
        $backend = new NmcliBackend($this->linuxRunner());
        $wifi = new WiFi($backend);

        $this->assertSame($backend, $wifi->backend());
    }

    #[Test]
    public function start_hotspot_on_a_backend_without_the_capability_throws_naming_both_classes(): void
    {
        $wifi = new WiFi(new NetworksetupBackend($this->darwinRunner()));

        try {
            $wifi->startHotspot(new HotspotConfig('femus-setup', 'password1', device: new Device('en0')));
            $this->fail('Expected UnsupportedOperation to be thrown.');
        } catch (UnsupportedOperation $e) {
            $this->assertStringContainsString('NetworksetupBackend', $e->getMessage());
            $this->assertStringContainsString('SupportsHotspot', $e->getMessage());
        }
    }

    #[Test]
    public function stop_hotspot_on_a_backend_without_the_capability_throws(): void
    {
        $wifi = new WiFi(new NetworksetupBackend($this->darwinRunner()));

        $this->expectException(UnsupportedOperation::class);

        $wifi->stopHotspot();
    }

    #[Test]
    public function is_hotspot_active_on_a_backend_without_the_capability_throws(): void
    {
        $wifi = new WiFi(new NetworksetupBackend($this->darwinRunner()));

        $this->expectException(UnsupportedOperation::class);

        $wifi->isHotspotActive();
    }

    #[Test]
    public function known_networks_on_a_backend_without_the_capability_throws(): void
    {
        $wifi = new WiFi(new NetworksetupBackend($this->darwinRunner()));

        $this->expectException(UnsupportedOperation::class);

        $wifi->knownNetworks();
    }

    #[Test]
    public function forget_on_a_backend_without_the_capability_throws(): void
    {
        $wifi = new WiFi(new NetworksetupBackend($this->darwinRunner()));

        $this->expectException(UnsupportedOperation::class);

        $wifi->forget('anything');
    }

    #[Test]
    public function supports_is_false_for_a_capability_the_backend_lacks(): void
    {
        $wifi = new WiFi(new NetworksetupBackend($this->darwinRunner()));

        $this->assertFalse($wifi->supports(SupportsHotspot::class));
        $this->assertFalse($wifi->supports(SupportsKnownNetworks::class));
    }

    #[Test]
    public function backend_factory_returns_nmcli_backend_for_linux(): void
    {
        $backend = BackendFactory::forOs(Os::Linux, new FakeCommandRunner([]));

        $this->assertInstanceOf(NmcliBackend::class, $backend);
    }

    #[Test]
    public function backend_factory_returns_networksetup_backend_for_darwin(): void
    {
        $backend = BackendFactory::forOs(Os::Darwin, new FakeCommandRunner([]));

        $this->assertInstanceOf(NetworksetupBackend::class, $backend);
    }

    #[Test]
    public function backend_factory_returns_netsh_backend_for_windows(): void
    {
        $backend = BackendFactory::forOs(Os::Windows, new FakeCommandRunner([]));

        $this->assertInstanceOf(NetshBackend::class, $backend);
    }

    #[Test]
    public function backend_factory_for_current_os_uses_the_given_runner(): void
    {
        $runner = new FakeCommandRunner([]);

        $backend = BackendFactory::forCurrentOs($runner);
        $expected = BackendFactory::forOs(Os::current(), $runner);

        $this->assertSame($expected::class, $backend::class);
    }

    #[Test]
    public function create_uses_the_backend_factory_for_the_current_os(): void
    {
        $runner = new FakeCommandRunner([]);

        $wifi = WiFi::create($runner);
        $expected = BackendFactory::forOs(Os::current(), $runner);

        $this->assertInstanceOf(WiFi::class, $wifi);
        $this->assertSame($expected::class, $wifi->backend()::class);
    }

    #[Test]
    public function scan_delegates_to_the_backend(): void
    {
        $wifi = new WiFi(new NmcliBackend($this->linuxRunner()));

        $networks = $wifi->scan();

        $this->assertCount(6, $networks);
    }
}
