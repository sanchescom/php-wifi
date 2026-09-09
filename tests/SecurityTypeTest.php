<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksCommand;

class SecurityTypeTest extends BaseTestCase
{
    public static function securityStrings(): array
    {
        return [
            'nmcli WPA3'          => ['WPA3', 'WPA3'],
            'macOS WPA3'          => ['WPA3 Personal', 'WPA3'],
            'nmcli WPA1 WPA2'     => ['WPA1 WPA2', 'WPA2'],
            'macOS WPA2'          => ['WPA2 Personal', 'WPA2'],
            'airport mixed'       => ['WPA(PSK/TKIP,AES/TKIP) WPA2(PSK/TKIP,AES/TKIP)', 'WPA2'],
            'netsh WPA2'          => ['WPA2-Personal', 'WPA2'],
            'netsh WPA3'          => ['WPA3-Personal', 'WPA3'],
            'nmcli WPA1 only'     => ['WPA1', 'WPA'],
            'WEP'                 => ['WEP', 'WEP'],
            'open'                => ['', 'Unknown'],
            'nmcli open'          => ['--', 'Unknown'],
            'garbage'             => ['802.1X', 'Unknown'],
        ];
    }

    #[Test]
    #[DataProvider('securityStrings')]
    public function it_classifies_security_strings(string $raw, string $expected): void
    {
        $network = new class(new NetworksCommand()) extends AbstractNetwork {
            public function connect(string $password, ?string $device = null): void
            {
            }

            public function disconnect(?string $device = null): void
            {
            }

            public function createFromArray(array $network): AbstractNetwork
            {
                return $this;
            }
        };
        $network->security = $raw;

        $this->assertSame($expected, $network->getSecurityType());
    }
}
