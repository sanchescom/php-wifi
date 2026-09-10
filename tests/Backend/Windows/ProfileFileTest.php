<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend\Windows;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\Windows\ProfileFile;
use Sanchescom\WiFi\Value\Security;

final class ProfileFileTest extends TestCase
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
    public function it_writes_an_escaped_profile_under_the_given_directory_and_deletes_it(): void
    {
        $profile = new ProfileFile('A&B <"x">', Security::WPA2, $this->dir);

        $file = $profile->create('p<a>&"b"');

        // tempnam() may return the /private/var form of a /var temp dir on macOS
        $this->assertSame(realpath($this->dir), realpath(dirname($file)));
        $this->assertStringStartsWith('php-wifi-', basename($file));

        $xml = (string) file_get_contents($file);
        $this->assertStringContainsString('<name>A&amp;B &lt;&quot;x&quot;&gt;</name>', $xml);
        $this->assertStringContainsString('<keyMaterial>p&lt;a&gt;&amp;&quot;b&quot;</keyMaterial>', $xml);
        $this->assertStringContainsString('<hex>' . bin2hex('A&B <"x">') . '</hex>', $xml);
        $this->assertStringContainsString('<authentication>WPA2PSK</authentication>', $xml);

        $this->assertTrue($profile->delete());
        $this->assertFileDoesNotExist($file);
        $this->assertFalse($profile->delete());
    }

    #[Test]
    public function an_ssid_with_path_characters_cannot_escape_the_directory(): void
    {
        $profile = new ProfileFile('../../evil', Security::WPA2, $this->dir);

        $file = $profile->create('x');

        // realpath(): on macOS sys_get_temp_dir() lives under /var, a symlink to /private/var
        $this->assertSame(realpath($this->dir), realpath(dirname($file)));
        $profile->delete();
    }

    #[Test]
    public function a_non_utf8_ssid_renders_the_replacement_character_instead_of_an_empty_name(): void
    {
        $profile = new ProfileFile("Caf\xE9", Security::WPA2, $this->dir);

        $file = $profile->create('p');
        $xml = (string) file_get_contents($file);

        $this->assertDoesNotMatchRegularExpression('/<name><\/name>/', $xml);
        $this->assertStringContainsString("\u{FFFD}", $xml);

        $profile->delete();
    }

    #[Test]
    public function an_open_network_uses_the_unknown_template(): void
    {
        $profile = new ProfileFile('Cafe Corner', Security::Open, $this->dir);

        $file = $profile->create('');
        $xml = (string) file_get_contents($file);

        $this->assertStringContainsString('<authentication>open</authentication>', $xml);
        $this->assertStringContainsString('<connectionMode>manual</connectionMode>', $xml);

        $profile->delete();
    }

    #[Test]
    public function an_ssid_matching_a_template_placeholder_cannot_inject_the_passphrase(): void
    {
        $profile = new ProfileFile('{key}', Security::WPA2, $this->dir);

        $file = $profile->create('hunter2');
        $xml = (string) file_get_contents($file);

        $this->assertStringContainsString('<name>{key}</name>', $xml);
        $this->assertSame(1, substr_count($xml, 'hunter2'));
        $this->assertStringNotContainsString('<name>hunter2', $xml);

        $profile->delete();
    }

    #[Test]
    public function ssids_matching_other_placeholders_render_literally(): void
    {
        $hexProfile = new ProfileFile('{hex}', Security::WPA2, $this->dir);
        $hexFile = $hexProfile->create('x');
        $hexXml = (string) file_get_contents($hexFile);
        $this->assertStringContainsString('<name>{hex}</name>', $hexXml);
        $hexProfile->delete();

        $ssidProfile = new ProfileFile('{ssid}', Security::WPA2, $this->dir);
        $ssidFile = $ssidProfile->create('x');
        $ssidXml = (string) file_get_contents($ssidFile);
        $this->assertStringContainsString('<name>{ssid}</name>', $ssidXml);
        $ssidProfile->delete();
    }

    #[Test]
    public function delete_without_a_created_file_returns_false(): void
    {
        $profile = new ProfileFile('Cafe Corner', Security::WPA2, $this->dir);

        $this->assertFalse($profile->delete());
    }

    #[Test]
    public function create_uses_the_system_temp_directory_when_none_is_given(): void
    {
        $profile = new ProfileFile('Cafe Corner', Security::WPA2);

        $file = $profile->create('x');

        $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($file)));

        $profile->delete();
    }

}
