<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend\Linux;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\Linux\HostapdConfig;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\HotspotConfig;

final class HostapdConfigTest extends TestCase
{
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

    #[Test]
    public function it_renders_the_2_4ghz_config_under_the_given_directory_and_deletes_it(): void
    {
        $config = new HostapdConfig('wlan0', new HotspotConfig('femus-setup', 'password1'), $this->dir);

        $file = $config->create();

        $this->assertSame(realpath($this->dir), realpath(dirname($file)));
        $this->assertStringStartsWith('php-wifi-hostapd-', basename($file));

        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString('interface=wlan0', $contents);
        $this->assertStringContainsString('ssid=femus-setup', $contents);
        $this->assertStringContainsString('hw_mode=g', $contents);
        $this->assertStringContainsString('channel=6', $contents);
        $this->assertStringContainsString('wpa=2', $contents);
        $this->assertStringContainsString('wpa_passphrase=password1', $contents);
        $this->assertStringContainsString('wpa_key_mgmt=WPA-PSK', $contents);
        $this->assertStringContainsString('rsn_pairwise=CCMP', $contents);

        $this->assertTrue($config->delete());
        $this->assertFileDoesNotExist($file);
        $this->assertFalse($config->delete());
    }

    #[Test]
    public function a_5ghz_band_uses_hw_mode_a_and_channel_36(): void
    {
        $config = new HostapdConfig('wlan0', new HotspotConfig('femus-setup', 'password1', Band::GHz5), $this->dir);

        $file = $config->create();
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('hw_mode=a', $contents);
        $this->assertStringContainsString('channel=36', $contents);

        $config->delete();
    }

    #[Test]
    public function a_6ghz_band_is_unsupported(): void
    {
        $this->expectException(UnsupportedOperation::class);

        new HostapdConfig('wlan0', new HotspotConfig('femus-setup', 'password1', Band::GHz6), $this->dir);
    }

    #[Test]
    public function an_ssid_containing_a_newline_is_rejected_as_an_injection_attempt(): void
    {
        $this->expectException(InvalidArgument::class);

        new HostapdConfig('wlan0', new HotspotConfig("femus\nssid=evil", 'password1'), $this->dir);
    }

    #[Test]
    public function an_ssid_containing_a_carriage_return_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);

        new HostapdConfig('wlan0', new HotspotConfig("femus\rssid=evil", 'password1'), $this->dir);
    }

    #[Test]
    public function a_passphrase_containing_a_newline_is_rejected_as_an_injection_attempt(): void
    {
        $this->expectException(InvalidArgument::class);

        new HostapdConfig('wlan0', new HotspotConfig('femus-setup', "hunter2\nssid=evil"), $this->dir);
    }

    #[Test]
    public function delete_without_a_created_file_returns_false(): void
    {
        $config = new HostapdConfig('wlan0', new HotspotConfig('femus-setup', 'password1'), $this->dir);

        $this->assertFalse($config->delete());
    }

    #[Test]
    public function create_uses_the_system_temp_directory_when_none_is_given(): void
    {
        $config = new HostapdConfig('wlan0', new HotspotConfig('femus-setup', 'password1'));

        $file = $config->create();

        $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($file)));

        $config->delete();
    }
}
