<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Shell;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Shell\ShellCommandRunner;

final class ShellCommandRunnerTest extends TestCase
{
    #[Test]
    public function it_captures_stdout_stderr_and_exit_code_separately(): void
    {
        $result = (new ShellCommandRunner())->run(new Command('sh', ['-c', 'printf out; printf err 1>&2; exit 3']));

        $this->assertSame(3, $result->exitCode);
        $this->assertSame('out', $result->stdout);
        $this->assertSame('err', $result->stderr);
        $this->assertFalse($result->isSuccessful());
    }

    #[Test]
    public function a_missing_program_is_a_non_zero_exit_not_an_exception(): void
    {
        $result = (new ShellCommandRunner())->run(new Command('definitely-not-a-program-xyz'));

        $this->assertNotSame(0, $result->exitCode);
        $this->assertTrue($result->stdout === '' || $result->stderr !== '');
    }

    /**
     * proc_open() with array-form arguments execs directly, and on some
     * platforms a failed exec (missing binary) is reported back
     * synchronously as a PHP E_WARNING, not just the non-zero exit code
     * this method already returns. PHPUnit's `failOnWarning="true"`
     * configuration turns any unsuppressed warning straight into a test
     * failure, so simply reaching the assertions below — with a clean exit
     * and no thrown exception — is itself part of what this test proves; a
     * regression here would fail loudly rather than silently.
     * `WpaCliBackend::commandExists()` relies on exactly this: probing for a
     * DHCP client that is not installed must never look like a PHP error.
     *
     * What stdout/stderr actually contain is platform-dependent and is NOT
     * part of the guarantee above — measured on a Raspberry Pi running
     * Debian 13 (PHP 8.2): there, proc_open() detects the failed exec of a
     * missing binary SYNCHRONOUSLY and returns a non-resource, so
     * ShellCommandRunner::run() itself takes the `!is_resource($process)`
     * fallback branch (see runViaPipes()) and fills $result->stderr with
     * its own "Unable to start: <program>" message. On this Mac, proc_open()
     * instead returns a resource for a child that forks and exits
     * immediately on the failed exec, writing nothing to either pipe, so
     * stderr comes back empty. Both are correct outcomes of the same
     * contract (non-zero exit, no warning, a CommandResult rather than a
     * thrown exception) — do not "fix" this back to asserting stderr is
     * always empty.
     */
    #[Test]
    public function probing_a_nonexistent_binary_emits_no_php_warning_and_produces_clean_output(): void
    {
        $command = new Command('php-wifi-definitely-not-a-binary');
        $result = (new ShellCommandRunner())->run($command);

        $this->assertNotSame(0, $result->exitCode);
        $this->assertSame('', $result->stdout);
        $this->assertContains($result->stderr, ['', 'Unable to start: ' . $command->describe()]);
    }

    #[Test]
    public function a_large_stderr_payload_does_not_deadlock_before_stdout_is_read(): void
    {
        $result = (new ShellCommandRunner())->run(new Command(
            'sh',
            ['-c', 'head -c 200000 /dev/zero | tr "\0" e 1>&2; printf OUT']
        ));

        $this->assertSame('OUT', $result->stdout);
        $this->assertSame(200000, strlen($result->stderr));
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    public function an_eintr_interrupted_select_is_retried_not_treated_as_eof(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            // No-op: the point of the alarm is only to interrupt the blocking
            // stream_select() call inside ShellCommandRunner::run() with EINTR.
        });

        try {
            pcntl_alarm(1);

            $result = (new ShellCommandRunner())->run(new Command(
                'sh',
                ['-c', 'sleep 2; printf HELLO; printf ERRBYTES 1>&2']
            ));

            $this->assertSame(0, $result->exitCode);
            $this->assertSame('HELLO', $result->stdout);
            $this->assertSame('ERRBYTES', $result->stderr);
        } finally {
            // pcntl has no getter for the previously installed handler, so the
            // best available restoration is: cancel any pending alarm and put
            // SIGALRM back to its default disposition.
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }
    }

    #[Test]
    public function the_file_capture_strategy_separates_stdout_stderr_and_exit_code_and_leaves_no_temp_file(): void
    {
        $before = glob(sys_get_temp_dir() . '/php-wifi-*') ?: [];

        $result = (new ShellCommandRunner(Os::Linux, captureViaFiles: true))->run(
            new Command('sh', ['-c', 'printf out; printf err 1>&2; exit 3'])
        );

        $this->assertSame(3, $result->exitCode);
        $this->assertSame('out', $result->stdout);
        $this->assertSame('err', $result->stderr);

        $after = glob(sys_get_temp_dir() . '/php-wifi-*') ?: [];
        $this->assertSame($before, $after);
    }

    #[Test]
    public function the_file_capture_strategy_also_survives_a_large_stderr_payload_and_leaves_no_temp_file(): void
    {
        $before = glob(sys_get_temp_dir() . '/php-wifi-*') ?: [];

        $result = (new ShellCommandRunner(Os::Linux, captureViaFiles: true))->run(new Command(
            'sh',
            ['-c', 'head -c 200000 /dev/zero | tr "\0" e 1>&2; printf OUT']
        ));

        $this->assertSame('OUT', $result->stdout);
        $this->assertSame(200000, strlen($result->stderr));

        $after = glob(sys_get_temp_dir() . '/php-wifi-*') ?: [];
        $this->assertSame($before, $after);
    }

    #[Test]
    public function it_feeds_stdin_to_the_child(): void
    {
        $runner = new ShellCommandRunner(Os::Linux);
        $result = $runner->run(new Command('cat', [], [], [], "hello\n"));

        $this->assertSame(0, $result->exitCode);
        $this->assertSame("hello\n", $result->stdout);
    }

    #[Test]
    public function it_feeds_stdin_to_the_child_via_the_file_capture_strategy(): void
    {
        $runner = new ShellCommandRunner(Os::Linux, captureViaFiles: true);
        $result = $runner->run(new Command('cat', [], [], [], "hello\n"));

        $this->assertSame(0, $result->exitCode);
        $this->assertSame("hello\n", $result->stdout);
    }

    /**
     * The brief's original proof reads the parent process's name via
     * `ps -o comm= -p $PPID` from a `sh -c` child, on the theory that a
     * shell-string invocation leaves a "sh" between php and the program while
     * an argv invocation does not. On this Mac (bash-as-/bin/sh) that is not
     * deterministic: bash tail-call-optimises a `-c` script whose last
     * command is a single simple external command by exec()-ing straight
     * into it, replacing the shell process in place — verified by hand:
     * `proc_open(["sh", "-c", "ps -o pid=,ppid=,comm= -p \$\$"], …)` reports
     * comm=ps for the *same* pid proc_open returned for "sh", for both the
     * old shell-string call and the new argv call. So the process-name probe
     * cannot tell the two implementations apart here.
     *
     * The portable proof instead: feed a program an argument containing a
     * shell metacharacter sequence and confirm it comes back byte-for-byte,
     * never expanded. Only a shell would interpret `$(id)`; execvp() never
     * does, whatever the argument contains.
     */
    #[Test]
    public function it_does_not_spawn_a_shell(): void
    {
        $runner = new ShellCommandRunner(Os::Linux);
        $result = $runner->run(new Command('printf', ['%s', '$(id)']));

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('$(id)', $result->stdout);
    }

    #[Test]
    public function the_environment_is_merged_not_replaced(): void
    {
        $hostPath = getenv('PATH');
        self::assertIsString($hostPath);
        self::assertNotSame('', $hostPath);

        $runner = new ShellCommandRunner(Os::Linux);
        $result = $runner->run(new Command('sh', ['-c', 'printf \'%s\\n%s\' "$LANG" "$PATH"'], ['LANG' => 'C']));

        // The child must see BOTH the injected variable and the host's own
        // PATH, byte for byte: proc_open() replaces the environment when it is
        // given an array, and a shell silently substitutes a default PATH when
        // none is set, which would hide a missing merge.
        $this->assertSame(['C', $hostPath], explode("\n", $result->stdout));
    }
}
