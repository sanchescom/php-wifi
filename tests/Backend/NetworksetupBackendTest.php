<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\NetworksetupBackend;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\NetworkCollection;

final class NetworksetupBackendTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/darwin';

    private function runner(): FakeCommandRunner
    {
        return new FakeCommandRunner([
            'SPAirPortDataType' => self::FIXTURES . '/SystemProfiler.txt',
            '-listallhardwareports' => self::FIXTURES . '/HardwarePorts.txt',
            '-setairportnetwork' => '',
            '-setairportpower' => '',
        ]);
    }

    #[Test]
    public function scan_runs_system_profiler_once_and_parses_with_the_system_profiler_parser(): void
    {
        $runner = $this->runner();
        $backend = new NetworksetupBackend($runner);

        $networks = $backend->scan();

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertCount(6, $networks);
        $this->assertSame('AlphaNet-foiEmE', $networks->connected()->first()?->ssid);

        $expected = new Command('system_profiler', ['SPAirPortDataType']);

        $this->assertCount(1, $runner->commands);
        $this->assertSame($expected->describe(), $runner->last()->describe());
        $this->assertSame($expected->env, $runner->last()->env);
    }

    #[Test]
    public function scan_falls_back_to_the_airport_parser_when_the_output_looks_like_the_legacy_table(): void
    {
        $runner = new FakeCommandRunner([
            'SPAirPortDataType' => self::FIXTURES . '/Airport.txt',
        ]);
        $backend = new NetworksetupBackend($runner);

        $networks = $backend->scan();

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertCount(4, $networks);
    }

    #[Test]
    public function connect_passes_the_password_as_a_secret_argument(): void
    {
        $runner = $this->runner();
        $backend = new NetworksetupBackend($runner);

        $backend->connect('Cafe Corner', Credentials::password('x y'), new Device('en0'));

        $command = $runner->last();

        $this->assertSame(
            ['-setairportnetwork', 'en0', 'Cafe Corner', 'x y'],
            $command->arguments,
        );
        $this->assertSame([3], $command->secretIndexes);
    }

    #[Test]
    public function connect_without_credentials_omits_the_password_argument(): void
    {
        $runner = $this->runner();
        $backend = new NetworksetupBackend($runner);

        $backend->connect('Cafe Corner', Credentials::none(), new Device('en0'));

        $command = $runner->last();

        $this->assertSame(['-setairportnetwork', 'en0', 'Cafe Corner'], $command->arguments);
        $this->assertSame([], $command->secretIndexes);
    }

    #[Test]
    public function disconnect_powers_the_device_off_then_on(): void
    {
        $runner = $this->runner();
        $backend = new NetworksetupBackend($runner);

        $backend->disconnect(new Device('en0'));

        $this->assertCount(2, $runner->commands);
        $this->assertSame(['-setairportpower', 'en0', 'off'], $runner->commands[0]->arguments);
        $this->assertSame(['-setairportpower', 'en0', 'on'], $runner->commands[1]->arguments);
    }

    #[Test]
    public function detect_device_returns_the_wifi_hardware_port_device(): void
    {
        $runner = $this->runner();
        $backend = new NetworksetupBackend($runner);

        $device = $backend->detectDevice();

        $this->assertEquals(new Device('en0'), $device);
    }

    #[Test]
    public function detect_device_throws_when_no_wifi_hardware_port_exists(): void
    {
        $runner = new FakeCommandRunner([
            '-listallhardwareports' => "\nHardware Port: Ethernet Adapter (en4)\nDevice: en4\nEthernet Address: 00:00:00:00:00:00\n",
        ]);
        $backend = new NetworksetupBackend($runner);

        try {
            $backend->detectDevice();
            $this->fail('Expected DeviceNotFound to be thrown.');
        } catch (DeviceNotFound $e) {
            $this->assertStringContainsString('networksetup -listallhardwareports', $e->getMessage());
        }
    }

    #[Test]
    public function connect_throws_permission_denied_when_networksetup_refuses(): void
    {
        $runner = new FakeCommandRunner([
            '-setairportnetwork' => [
                'output' => '',
                'exit' => 1,
                'stderr' => 'Not authorized to control Wi-Fi.',
            ],
        ]);
        $backend = new NetworksetupBackend($runner);

        $this->expectException(PermissionDenied::class);

        $backend->connect('Cafe Corner', Credentials::password('hunter2'), new Device('en0'));
    }

    #[Test]
    public function connect_throws_command_failed_masking_the_password_on_other_failures(): void
    {
        $runner = new FakeCommandRunner([
            '-setairportnetwork' => [
                'output' => '',
                'exit' => 1,
                'stderr' => 'Error joining the network.',
            ],
        ]);
        $backend = new NetworksetupBackend($runner);

        try {
            $backend->connect('Cafe Corner', Credentials::password('hunter2'), new Device('en0'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $e) {
            $this->assertStringContainsString('***', $e->getMessage());
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }
}
