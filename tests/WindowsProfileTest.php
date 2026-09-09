<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\Exceptions\CommandException;
use Sanchescom\WiFi\System\Windows\Network;
use Sanchescom\WiFi\System\Windows\Profile;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksCommand;

class WindowsProfileTest extends BaseTestCase
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
        $profile = new Profile('A&B <"x">', 'WPA2', $this->dir);

        $file = $profile->create('p<a>&"b"');

        // tempnam() may return the /private/var form of a /var temp dir on macOS
        $this->assertSame(realpath($this->dir), realpath(dirname($file)));
        $this->assertStringStartsWith('php-wifi-', basename($file));
        $xml = file_get_contents($file);
        $this->assertStringContainsString('<name>A&amp;B &lt;&quot;x&quot;&gt;</name>', $xml);
        $this->assertStringContainsString('<keyMaterial>p&lt;a&gt;&amp;&quot;b&quot;</keyMaterial>', $xml);
        $this->assertStringContainsString('<hex>' . to_hex('A&B <"x">') . '</hex>', $xml);
        $this->assertStringContainsString('<authentication>WPA2PSK</authentication>', $xml);

        $this->assertTrue($profile->delete());
        $this->assertFileDoesNotExist($file);
        $this->assertFalse($profile->delete());
    }

    #[Test]
    public function an_ssid_with_path_characters_cannot_escape_the_directory(): void
    {
        $profile = new Profile('../../evil', 'WPA2', $this->dir);

        $file = $profile->create('x');

        // realpath(): on macOS sys_get_temp_dir() lives under /var, a symlink to /private/var
        $this->assertSame(realpath($this->dir), realpath(dirname($file)));
        $profile->delete();
    }

    #[Test]
    public function connect_deletes_the_profile_even_when_netsh_fails(): void
    {
        $command = new class () extends NetworksCommand {
            public function execute(string $command)
            {
                $this->lastCommand = $command;
                throw new CommandException($command, 'boom', 1);
            }
        };
        $network = (new Network($command))
            ->createFromArray(['AlphaNet-foiEmE', '', 'WPA2-Personal', 'CCMP', '00:11:22:33:44:55', '50', '', '6', '', '']);

        try {
            $network->connect('secret', 'Wi-Fi');
            $this->fail('CommandException expected');
        } catch (CommandException) {
        }

        preg_match('/filename="([^"]+)"/', $command->getLastCommand(), $m);
        $this->assertNotEmpty($m[1] ?? '', 'profile path missing from the command');
        $this->assertFileDoesNotExist($m[1]);
    }
}
