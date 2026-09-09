<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\Os;

final class CommandFailedTest extends TestCase
{
    #[Test]
    public function the_message_shows_the_masked_command_and_exit_code_but_never_the_secret(): void
    {
        $command = new Command('nmcli', ['device', 'wifi', 'connect', 'Home', 'password', 'hunter2'], [], [5]);
        $result = new CommandResult(4, '', 'Error: Connection activation failed');

        $e = CommandFailed::fromResult($command, $result, Os::Linux);

        $this->assertStringContainsString("'password' '***'", $e->getMessage());
        $this->assertStringContainsString('exited with 4', $e->getMessage());
        $this->assertStringContainsString('Connection activation failed', $e->getMessage());
        $this->assertStringNotContainsString('hunter2', $e->getMessage());
        $this->assertSame($result, $e->result);
    }

    #[Test]
    public function permission_denied_is_recognised_and_points_at_the_polkit_docs(): void
    {
        $result = new CommandResult(4, '', 'Error: Failed to add/activate new connection: Not authorized to control networking.');

        $this->assertTrue(PermissionDenied::looksLike($result));
        $e = PermissionDenied::fromResult(new Command('nmcli', ['device', 'wifi', 'connect', 'x']), $result, Os::Linux);
        $this->assertInstanceOf(CommandFailed::class, $e);
        $this->assertStringContainsString('README', $e->getMessage());
        $this->assertStringContainsString('polkit', $e->getMessage());
        $this->assertFalse(PermissionDenied::looksLike(new CommandResult(1, '', 'No network with SSID')));
    }

    #[Test]
    public function permission_denied_on_darwin_points_at_admin_privileges_instead_of_polkit(): void
    {
        $result = new CommandResult(1, '', 'Not authorized to control Wi-Fi.');

        $e = PermissionDenied::fromResult(new Command('networksetup', ['-setairportnetwork', 'en0', 'x']), $result, Os::Darwin);

        $this->assertStringContainsString('administrator', $e->getMessage());
        $this->assertStringNotContainsString('polkit', $e->getMessage());
    }

    #[Test]
    public function permission_denied_on_windows_points_at_admin_privileges_instead_of_polkit(): void
    {
        $result = new CommandResult(1, '', 'Insufficient privileges to perform this operation.');

        $e = PermissionDenied::fromResult(new Command('netsh', ['wlan', 'connect']), $result, Os::Windows);

        $this->assertStringContainsString('administrator', $e->getMessage());
        $this->assertStringNotContainsString('polkit', $e->getMessage());
    }

    #[Test]
    public function permission_denied_on_linux_still_points_at_polkit(): void
    {
        $result = new CommandResult(4, '', 'Not authorized to control networking.');

        $e = PermissionDenied::fromResult(new Command('nmcli', ['device', 'wifi', 'connect', 'x']), $result, Os::Linux);

        $this->assertStringContainsString('polkit', $e->getMessage());
        $this->assertStringNotContainsString('administrator', $e->getMessage());
    }
}
