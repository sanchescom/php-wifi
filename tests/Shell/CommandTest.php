<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Shell;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\Os;

final class CommandTest extends TestCase
{
    #[Test]
    public function posix_rendering_escapes_every_token_and_prefixes_env(): void
    {
        $command = new Command('nmcli', ['device', 'wifi', 'connect', "x'; rm -rf ~ #", 'password', 'pa$$`w"ord'], ['LANG' => 'C']);

        $this->assertSame(
            "LANG='C' 'nmcli' 'device' 'wifi' 'connect' 'x'\\''; rm -rf ~ #' 'password' 'pa\$\$`w\"ord'",
            $command->toShell(Os::Linux)
        );
        $this->assertSame($command->toShell(Os::Linux), $command->toShell(Os::Darwin));
    }

    #[Test]
    public function a_posix_argument_survives_a_real_shell_intact(): void
    {
        $hostile = "x'; rm -rf ~ #";
        $rendered = (new Command('printf', ['%s', $hostile]))->toShell(Os::Linux);

        $this->assertSame($hostile, shell_exec($rendered));
    }

    #[Test]
    public function windows_rendering_uses_cmd_safe_double_quotes(): void
    {
        $command = new Command('netsh', ['wlan', 'connect', 'interface=Wi-Fi "2"', 'ssid=Cafe 100%!', 'name=evil\\']);

        $this->assertSame(
            '"netsh" "wlan" "connect" "interface=Wi-Fi  2 " "ssid=Cafe 100  " "name=evil\\\\"',
            $command->toShell(Os::Windows)
        );
    }

    #[Test]
    public function windows_rendering_ignores_env(): void
    {
        $this->assertSame('"netsh" "wlan"', (new Command('netsh', ['wlan'], ['LANG' => 'C']))->toShell(Os::Windows));
    }

    #[Test]
    public function secrets_are_masked_in_display_but_not_in_shell(): void
    {
        $command = new Command('nmcli', ['connect', 'Home', 'password', 'hunter2'], [], [3]);

        $this->assertStringContainsString("'hunter2'", $command->toShell(Os::Linux));
        $this->assertSame("'nmcli' 'connect' 'Home' 'password' '***'", $command->toDisplay(Os::Linux));
        $this->assertSame('nmcli connect Home password ***', $command->describe());
    }

    #[Test]
    public function describe_is_the_unescaped_program_and_arguments(): void
    {
        $this->assertSame('nmcli -t -f DEVICE,TYPE device', (new Command('nmcli', ['-t', '-f', 'DEVICE,TYPE', 'device']))->describe());
    }
}
