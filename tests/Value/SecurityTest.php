<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Value\Security;

final class SecurityTest extends TestCase
{
    /** @return array<string, array{string, Security}> */
    public static function descriptions(): array
    {
        return [
            'WPA3' => ['WPA3', Security::WPA3],
            'WPA3 Personal' => ['WPA3 Personal', Security::WPA3],
            'WPA1 WPA2' => ['WPA1 WPA2', Security::WPA2],
            'WPA2 Personal' => ['WPA2 Personal', Security::WPA2],
            'mixed WPA/WPA2' => ['WPA(PSK/TKIP,AES/TKIP) WPA2(PSK/TKIP,AES/TKIP)', Security::WPA2],
            'WPA2-Personal' => ['WPA2-Personal', Security::WPA2],
            'WPA3-Personal' => ['WPA3-Personal', Security::WPA3],
            'WPA1' => ['WPA1', Security::WPA],
            'WEP' => ['WEP', Security::WEP],
            'empty' => ['', Security::Open],
            'dashes' => ['--', Security::Open],
            'none' => ['(none)', Security::Open],
            'garbage' => ['802.1X', Security::Unknown],
        ];
    }

    #[Test]
    #[DataProvider('descriptions')]
    public function it_classifies_security_strings(string $raw, Security $expected): void
    {
        $this->assertSame($expected, Security::fromDescription($raw));
    }

    /** @return array<string, array{Security, string}> */
    public static function templates(): array
    {
        return [
            'WPA3' => [Security::WPA3, 'WPA3'],
            'WPA2' => [Security::WPA2, 'WPA2'],
            'WPA' => [Security::WPA, 'WPA'],
            'WEP' => [Security::WEP, 'WEP'],
            'Open' => [Security::Open, 'Unknown'],
            'Unknown' => [Security::Unknown, 'Unknown'],
        ];
    }

    #[Test]
    #[DataProvider('templates')]
    public function windows_profile_template_maps_to_the_netsh_basename(Security $security, string $expected): void
    {
        $this->assertSame($expected, $security->windowsProfileTemplate());
    }
}
