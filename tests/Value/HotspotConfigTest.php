<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Value;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\HotspotConfig;

final class HotspotConfigTest extends TestCase
{
    #[Test]
    public function a_valid_config_keeps_its_fields(): void
    {
        $device = new Device('wlan0');

        $config = new HotspotConfig('MyHotspot', 'a-strong-passphrase', Band::GHz5, $device);

        $this->assertSame('MyHotspot', $config->ssid);
        $this->assertSame('a-strong-passphrase', $config->password);
        $this->assertSame(Band::GHz5, $config->band);
        $this->assertSame($device, $config->device);
    }

    #[Test]
    public function a_config_without_band_or_device_defaults_both_to_null(): void
    {
        $config = new HotspotConfig('MyHotspot', 'a-strong-passphrase');

        $this->assertNull($config->band);
        $this->assertNull($config->device);
    }

    #[Test]
    public function a_password_shorter_than_eight_characters_throws(): void
    {
        $this->expectException(InvalidArgument::class);

        new HotspotConfig('MyHotspot', 'short');
    }

    #[Test]
    public function a_sixty_four_character_password_throws(): void
    {
        $this->expectException(InvalidArgument::class);

        new HotspotConfig('MyHotspot', str_repeat('a', 64));
    }

    #[Test]
    public function an_empty_ssid_throws(): void
    {
        $this->expectException(InvalidArgument::class);

        new HotspotConfig('', 'a-strong-passphrase');
    }

    #[Test]
    public function a_thirty_three_byte_ssid_throws(): void
    {
        $this->expectException(InvalidArgument::class);

        new HotspotConfig(str_repeat('a', 33), 'a-strong-passphrase');
    }
}
