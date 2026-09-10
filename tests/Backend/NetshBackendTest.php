<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\NetshBackend;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\NetworkCollection;

final class NetshBackendTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/windows';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/php-wifi-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function runner(): FakeCommandRunner
    {
        return new FakeCommandRunner([
            'networks mode=Bssid' => self::FIXTURES . '/Networks.txt',
            'wlan show interfaces' => self::FIXTURES . '/Interfaces.txt',
            'wlan add profile' => '',
            'wlan connect interface=' => '',
            'wlan disconnect interface=' => '',
        ]);
    }

    #[Test]
    public function scan_runs_the_two_netsh_commands_and_marks_the_connected_network(): void
    {
        $runner = $this->runner();
        $backend = new NetshBackend($runner, $this->dir);

        $networks = $backend->scan();

        $this->assertInstanceOf(NetworkCollection::class, $networks);
        $this->assertCount(6, $networks);
        $this->assertCount(1, $networks->connected());
        $this->assertSame('AlphaNet-foiEmE', $networks->connected()->first()?->ssid);

        $this->assertCount(2, $runner->commands);
        $this->assertSame('cmd', $runner->commands[0]->program);
        $this->assertSame(
            ['/c', 'chcp 65001 >nul & netsh wlan show networks mode=Bssid'],
            $runner->commands[0]->arguments,
        );
        $this->assertSame('netsh', $runner->commands[1]->program);
        $this->assertSame(['wlan', 'show', 'interfaces'], $runner->commands[1]->arguments);
    }

    #[Test]
    public function scan_leaves_every_network_disconnected_when_the_interface_is_disconnected(): void
    {
        $runner = new FakeCommandRunner([
            'networks mode=Bssid' => self::FIXTURES . '/Networks.txt',
            'wlan show interfaces' => "There is 1 interface on the system:\n\n    Name : Wireless\n    State : disconnected\n",
        ]);
        $backend = new NetshBackend($runner, $this->dir);

        $networks = $backend->scan();

        $this->assertCount(6, $networks);
        $this->assertCount(0, $networks->connected());
    }

    #[Test]
    public function detect_device_returns_the_interface_device(): void
    {
        $runner = $this->runner();
        $backend = new NetshBackend($runner, $this->dir);

        $device = $backend->detectDevice();

        $this->assertEquals(new Device('Wireless'), $device);
    }

    #[Test]
    public function detect_device_throws_when_no_wireless_interface_is_listed(): void
    {
        $runner = new FakeCommandRunner([
            'wlan show interfaces' => "There are 0 interfaces on the system.\n",
        ]);
        $backend = new NetshBackend($runner, $this->dir);

        try {
            $backend->detectDevice();
            $this->fail('Expected DeviceNotFound to be thrown.');
        } catch (DeviceNotFound $e) {
            $this->assertStringContainsString('netsh wlan show interfaces', $e->getMessage());
        }
    }

    #[Test]
    public function connect_scans_then_writes_a_profile_and_runs_add_and_connect(): void
    {
        $runner = $this->runner();
        $backend = new NetshBackend($runner, $this->dir);

        $backend->connect('AlphaNet-foiEmE', Credentials::password('hunter2'), new Device('Wireless'));

        $this->assertCount(4, $runner->commands);

        $add = $runner->commands[2];
        $this->assertSame('netsh', $add->program);
        $this->assertSame(['wlan', 'add', 'profile'], array_slice($add->arguments, 0, 3));
        $this->assertMatchesRegularExpression(
            '#^filename=(?:/private)?' . preg_quote(realpath($this->dir), '#') . '/php-wifi-#',
            $add->arguments[3],
        );

        $connect = $runner->commands[3];
        $this->assertSame(
            ['wlan', 'connect', 'interface=Wireless', 'ssid=AlphaNet-foiEmE', 'name=AlphaNet-foiEmE'],
            $connect->arguments,
        );

        $profilePath = substr($add->arguments[3], strlen('filename='));
        $this->assertFileDoesNotExist($profilePath);
    }

    #[Test]
    public function connect_never_puts_the_password_in_a_command_argument(): void
    {
        $runner = $this->runner();
        $backend = new NetshBackend($runner, $this->dir);

        $backend->connect('AlphaNet-foiEmE', Credentials::password('hunter2'), new Device('Wireless'));

        foreach ($runner->commands as $command) {
            foreach ($command->arguments as $argument) {
                $this->assertStringNotContainsString('hunter2', $argument);
            }
        }
    }

    #[Test]
    public function connect_falls_back_to_unknown_security_when_the_ssid_is_not_found_in_the_scan(): void
    {
        $runner = $this->runner();
        $backend = new NetshBackend($runner, $this->dir);

        $backend->connect('Unknown Network', Credentials::none(), new Device('Wireless'));

        $add = $runner->commands[2];
        $profilePath = substr($add->arguments[3], strlen('filename='));
        $this->assertFileDoesNotExist($profilePath);

        $connect = $runner->commands[3];
        $this->assertSame(
            ['wlan', 'connect', 'interface=Wireless', 'ssid=Unknown Network', 'name=Unknown Network'],
            $connect->arguments,
        );
    }

    #[Test]
    public function connect_with_no_credentials_renders_the_open_template_with_an_empty_key_and_still_cleans_up(): void
    {
        $inner = $this->runner();
        $runner = new class ($inner) implements CommandRunner {
            public ?string $capturedProfileXml = null;

            public function __construct(private readonly CommandRunner $inner)
            {
            }

            public function run(Command $command): CommandResult
            {
                // Capture the rendered profile file's content while it still
                // exists on disk — NetshBackend deletes it in a `finally`
                // block right after the "connect" command below returns.
                if ($command->program === 'netsh'
                    && ($command->arguments[0] ?? null) === 'wlan'
                    && ($command->arguments[1] ?? null) === 'add'
                ) {
                    $filenameArg = (string) ($command->arguments[3] ?? '');
                    $path = substr($filenameArg, strlen('filename='));
                    $this->capturedProfileXml = (string) file_get_contents($path);
                }

                return $this->inner->run($command);
            }
        };

        $backend = new NetshBackend($runner, $this->dir);

        $backend->connect('Unknown Network', Credentials::none(), new Device('Wireless'));

        $this->assertNotNull($runner->capturedProfileXml);
        $this->assertStringContainsString('<authentication>open</authentication>', $runner->capturedProfileXml);
        $this->assertStringContainsString('<connectionMode>manual</connectionMode>', $runner->capturedProfileXml);
        $this->assertStringNotContainsString('<keyMaterial>', $runner->capturedProfileXml);

        $add = $inner->commands[2];
        $profilePath = substr($add->arguments[3], strlen('filename='));
        $this->assertFileDoesNotExist($profilePath);
    }

    #[Test]
    public function connect_deletes_the_profile_file_even_when_netsh_connect_fails(): void
    {
        $runner = new FakeCommandRunner([
            'networks mode=Bssid' => self::FIXTURES . '/Networks.txt',
            'wlan show interfaces' => self::FIXTURES . '/Interfaces.txt',
            'wlan add profile' => '',
            'wlan connect interface=' => [
                'output' => '',
                'exit' => 1,
                'stderr' => 'The wireless connection could not be established.',
            ],
        ]);
        $backend = new NetshBackend($runner, $this->dir);

        $profilePath = null;

        try {
            $backend->connect('AlphaNet-foiEmE', Credentials::password('hunter2'), new Device('Wireless'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed $e) {
            $add = $runner->commands[2];
            $profilePath = substr($add->arguments[3], strlen('filename='));
        }

        $this->assertNotNull($profilePath);
        $this->assertFileDoesNotExist($profilePath);
    }

    #[Test]
    public function connect_never_creates_a_profile_file_when_the_initial_scan_fails(): void
    {
        $runner = new FakeCommandRunner([
            'networks mode=Bssid' => [
                'output' => '',
                'exit' => 1,
                'stderr' => 'The wireless local area network interface is powered down.',
            ],
        ]);
        $backend = new NetshBackend($runner, $this->dir);

        try {
            $backend->connect('AlphaNet-foiEmE', Credentials::password('hunter2'), new Device('Wireless'));
            $this->fail('Expected CommandFailed to be thrown.');
        } catch (CommandFailed) {
        }

        $this->assertSame([], glob($this->dir . '/php-wifi-*'));
    }

    #[Test]
    public function disconnect_runs_the_exact_netsh_command(): void
    {
        $runner = $this->runner();
        $backend = new NetshBackend($runner, $this->dir);

        $backend->disconnect(new Device('Wireless'));

        $this->assertSame(['wlan', 'disconnect', 'interface=Wireless'], $runner->last()->arguments);
    }

    #[Test]
    public function connect_throws_permission_denied_when_netsh_refuses(): void
    {
        $runner = new FakeCommandRunner([
            'networks mode=Bssid' => self::FIXTURES . '/Networks.txt',
            'wlan show interfaces' => self::FIXTURES . '/Interfaces.txt',
            'wlan add profile' => [
                'output' => '',
                'exit' => 1,
                'stderr' => 'Not authorized to configure wireless profiles.',
            ],
        ]);
        $backend = new NetshBackend($runner, $this->dir);

        $this->expectException(PermissionDenied::class);

        $backend->connect('AlphaNet-foiEmE', Credentials::password('hunter2'), new Device('Wireless'));
    }
}
