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
    public function a_channel_without_a_band_brings_the_band_it_belongs_to(): void
    {
        $this->assertSame(Band::GHz2_4, (new HotspotConfig('MyHotspot', 'a-strong-passphrase', channel: 11))->resolvedBand());
        $this->assertSame(Band::GHz5, (new HotspotConfig('MyHotspot', 'a-strong-passphrase', channel: 44))->resolvedBand());
        $this->assertNull((new HotspotConfig('MyHotspot', 'a-strong-passphrase'))->resolvedBand());
    }

    #[Test]
    public function a_channel_that_is_not_on_the_given_band_throws(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('Channel 36 does not exist on the 2.4 GHz band.');

        new HotspotConfig('MyHotspot', 'a-strong-passphrase', Band::GHz2_4, channel: 36);
    }

    #[Test]
    public function a_channel_that_exists_on_neither_band_throws(): void
    {
        $this->expectException(InvalidArgument::class);

        new HotspotConfig('MyHotspot', 'a-strong-passphrase', channel: 15);
    }

    /** The country ends up as a line of hostapd's config: anything but two letters could carry a directive. */
    #[Test]
    public function a_country_that_is_not_two_upper_case_letters_throws(): void
    {
        foreach (['de', 'DEU', "DE\nssid=evil", ''] as $country) {
            try {
                new HotspotConfig('MyHotspot', 'a-strong-passphrase', country: $country);
                $this->fail(sprintf('Expected InvalidArgument for %s.', json_encode($country)));
            } catch (InvalidArgument) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('DE', (new HotspotConfig('MyHotspot', 'a-strong-passphrase', country: 'DE'))->country);
    }

    #[Test]
    public function a_thirty_three_byte_ssid_throws(): void
    {
        $this->expectException(InvalidArgument::class);

        new HotspotConfig(str_repeat('a', 33), 'a-strong-passphrase');
    }
}
