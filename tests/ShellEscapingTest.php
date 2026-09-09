<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\System\Darwin\Network as DarwinNetwork;
use Sanchescom\WiFi\System\Linux\Network as LinuxNetwork;
use Sanchescom\WiFi\System\Windows\Network as WindowsNetwork;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksCommand;

class ShellEscapingTest extends BaseTestCase
{
    private const HOSTILE_SSID = "x'; rm -rf ~ #";
    private const HOSTILE_PASSWORD = 'pa$$`w"ord';

    #[Test]
    public function linux_connect_quotes_every_argument(): void
    {
        $network = (new LinuxNetwork($command = new NetworksCommand()))
            ->createFromArray(['no', self::HOSTILE_SSID, '00:11:22:33:44:55', 'Infra', '6', '2437 MHz', '50', 'WPA2', '', '']);

        $network->connect(self::HOSTILE_PASSWORD, 'wlan0');

        $this->assertSame(
            'LANG=C nmcli -w 10 device wifi connect '.escapeshellarg(self::HOSTILE_SSID)
            .' password '.escapeshellarg(self::HOSTILE_PASSWORD).' ifname '.escapeshellarg('wlan0'),
            $command->getLastCommand()
        );
        $this->assertSame(
            self::HOSTILE_SSID,
            shell_exec('printf %s ' . escapeshellarg(self::HOSTILE_SSID)),
            'escapeshellarg must hand the shell the SSID as a single intact argument'
        );
    }

    #[Test]
    public function darwin_connect_and_disconnect_quote_every_argument(): void
    {
        $network = (new DarwinNetwork($command = new NetworksCommand()))
            ->createFromArray([self::HOSTILE_SSID, '', '-60', '6', '', '', 'WPA2 Personal']);

        $network->connect(self::HOSTILE_PASSWORD, 'en0');
        $this->assertSame(
            'networksetup -setairportnetwork '.escapeshellarg('en0').' '
            .escapeshellarg(self::HOSTILE_SSID).' '.escapeshellarg(self::HOSTILE_PASSWORD),
            $command->getLastCommand()
        );

        $network->disconnect('en0');
        $this->assertSame(
            'networksetup -removepreferredwirelessnetwork '.escapeshellarg('en0').' '.escapeshellarg(self::HOSTILE_SSID)
            .' && networksetup -setairportpower '.escapeshellarg('en0').' off'
            .' && networksetup -setairportpower '.escapeshellarg('en0').' on',
            $command->getLastCommand()
        );
    }

    #[Test]
    public function windows_quoting_strips_cmd_metacharacters(): void
    {
        $network = (new WindowsNetwork($command = new NetworksCommand()))
            ->createFromArray(['Cafe "Net" 100%!', '', 'WPA2-Personal', 'CCMP', '00:11:22:33:44:55', '50', '', '6', '', '']);

        $network->disconnect('Wi-Fi "2"');

        $this->assertSame('netsh wlan disconnect interface="Wi-Fi  2 "', $command->getLastCommand());
    }
}
