<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend\Linux;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\Linux\ToolPath;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\ShellCommandRunner;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;

final class ToolPathTest extends TestCase
{
    #[Test]
    public function resolve_returns_the_trimmed_first_line_of_a_successful_which(): void
    {
        $runner = new FakeCommandRunner(['which wpa_cli' => "/usr/sbin/wpa_cli\n"]);
        $toolPath = new ToolPath($runner);

        $this->assertSame('/usr/sbin/wpa_cli', $toolPath->resolve('wpa_cli'));
    }

    #[Test]
    public function resolve_returns_null_when_which_exits_non_zero(): void
    {
        $runner = new FakeCommandRunner(['which dhclient' => ['output' => '', 'exit' => 1]]);
        $toolPath = new ToolPath($runner);

        $this->assertNull($toolPath->resolve('dhclient'));
    }

    #[Test]
    public function resolve_runs_which_with_a_path_covering_the_usual_system_directories(): void
    {
        $runner = new FakeCommandRunner(['which iw' => "/usr/sbin/iw\n"]);
        $toolPath = new ToolPath($runner);

        $toolPath->resolve('iw');

        $this->assertEquals(
            new Command('which', ['iw'], ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin']),
            $runner->last(),
        );
    }

    #[Test]
    public function resolve_caches_the_answer_running_which_only_once_per_tool(): void
    {
        $runner = new FakeCommandRunner(['which iw' => "/usr/sbin/iw\n"]);
        $toolPath = new ToolPath($runner);

        $this->assertSame('/usr/sbin/iw', $toolPath->resolve('iw'));
        $this->assertSame('/usr/sbin/iw', $toolPath->resolve('iw'));
        $this->assertSame('/usr/sbin/iw', $toolPath->resolve('iw'));

        $this->assertCount(1, $runner->commands);
    }

    #[Test]
    public function resolve_caches_a_negative_answer_too(): void
    {
        $runner = new FakeCommandRunner(['which hostapd' => ['output' => '', 'exit' => 1]]);
        $toolPath = new ToolPath($runner);

        $this->assertNull($toolPath->resolve('hostapd'));
        $this->assertNull($toolPath->resolve('hostapd'));

        $this->assertCount(1, $runner->commands);
    }

    #[Test]
    public function resolve_resolves_each_tool_independently(): void
    {
        $runner = new FakeCommandRunner([
            'which iw' => "/usr/sbin/iw\n",
            'which ip' => "/usr/sbin/ip\n",
        ]);
        $toolPath = new ToolPath($runner);

        $this->assertSame('/usr/sbin/iw', $toolPath->resolve('iw'));
        $this->assertSame('/usr/sbin/ip', $toolPath->resolve('ip'));
        $this->assertCount(2, $runner->commands);
    }

    // --- resolve() against a real process, no fixture involved ---------------

    /**
     * Every other test in this file drives {@see FakeCommandRunner}, which
     * never execs anything — nothing there could have caught the defect this
     * class exists to fix (`proc_open()` resolving a bare program name
     * through PHP's own PATH, ignoring any `PATH` passed in `$env`). This
     * proves the real mechanism — `which <name>` through a genuine
     * {@see ShellCommandRunner} — actually resolves a real binary on this
     * machine, for a real process, no fixture involved.
     */
    #[Test]
    public function resolve_finds_a_binary_that_certainly_exists_and_is_executable(): void
    {
        $path = (new ToolPath(ShellCommandRunner::forCurrentOs()))->resolve('ls');

        $this->assertIsString($path);
        $this->assertTrue(is_executable($path));
    }

    #[Test]
    public function resolve_returns_null_for_a_binary_that_certainly_does_not_exist(): void
    {
        $path = (new ToolPath(ShellCommandRunner::forCurrentOs()))->resolve('php-wifi-definitely-not-a-binary');

        $this->assertNull($path);
    }
}
