<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Shell;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Shell\Command;
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
}
