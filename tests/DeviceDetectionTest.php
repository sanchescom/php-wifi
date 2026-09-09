<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\Exceptions\DeviceNotFoundException;
use Sanchescom\WiFi\System\Linux\Network as LinuxNetwork;
use Sanchescom\WiFi\Test\Darwin\Mocks\NetworksCommand as DarwinCommand;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksCommand as LinuxCommand;
use Sanchescom\WiFi\Test\Windows\Mocks\NetworksCommand as WindowsCommand;
use Sanchescom\WiFi\WiFi;

class DeviceDetectionTest extends BaseTestCase
{
    private function first(string $commandClass, string $os)
    {
        WiFi::setCommandClass($commandClass);
        WiFi::setPhpOperationSystem($os);

        return WiFi::scan()->getBySsid('AlphaNet-foiEmE');
    }

    #[Test]
    public function linux_detects_the_first_wifi_device(): void
    {
        $network = $this->first(LinuxCommand::class, 'Linux');
        $network->disconnect();

        $command = $network->getCommand();
        $this->assertSame('LANG=C nmcli -t -f DEVICE,TYPE device', $command->commands[count($command->commands) - 2]);
        $this->assertSame('LANG=C nmcli device disconnect ' . escapeshellarg('wlan0'), $command->getLastCommand());
    }

    #[Test]
    public function darwin_detects_the_wifi_hardware_port(): void
    {
        $network = $this->first(DarwinCommand::class, 'Darwin');
        $network->connect('123');

        $this->assertStringStartsWith('networksetup -setairportnetwork ' . escapeshellarg('en0') . ' ', $network->getCommand()->getLastCommand());
    }

    #[Test]
    public function windows_detects_the_first_wlan_interface(): void
    {
        $network = $this->first(WindowsCommand::class, 'Windows');
        $network->disconnect();

        $this->assertSame('netsh wlan disconnect interface="Wireless"', $network->getCommand()->getLastCommand());
    }

    #[Test]
    public function an_explicit_device_skips_detection(): void
    {
        $network = $this->first(LinuxCommand::class, 'Linux');
        $before = count($network->getCommand()->commands);

        $network->disconnect('wlan9');

        $this->assertCount($before + 1, $network->getCommand()->commands);
        $this->assertSame('LANG=C nmcli device disconnect ' . escapeshellarg('wlan9'), $network->getCommand()->getLastCommand());
    }

    #[Test]
    public function no_wifi_device_is_an_explicit_error(): void
    {
        $command = new class () extends LinuxCommand {
            public function execute(string $command)
            {
                $this->lastCommand = $command;
                $this->commands[] = $command;

                return "eth0:ethernet:connected\nlo:loopback:connected (externally)\n";
            }
        };
        $network = (new LinuxNetwork($command))
            ->createFromArray(['no', 'x', '00:11:22:33:44:55', 'Infra', '6', '2437 MHz', '50', 'WPA2', '', '']);

        $this->expectException(DeviceNotFoundException::class);
        $network->disconnect();
    }
}
