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
}
