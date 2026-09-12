<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sanchescom\WiFi\Backend\BackendFactory;
use Sanchescom\WiFi\Backend\NmcliBackend;
use Sanchescom\WiFi\Backend\WpaCliBackend;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;

final class BackendFactoryTest extends TestCase
{
    #[Test]
    public function forLinuxPicksNmcliWhenNetworkManagerIsRunning(): void
    {
        $runner = new FakeCommandRunner(['-t -f RUNNING general' => "running\n"]);

        $backend = BackendFactory::forLinux($runner);

        self::assertInstanceOf(NmcliBackend::class, $backend);
    }

    #[Test]
    public function forLinuxPicksWpaCliWhenNetworkManagerIsNotRunning(): void
    {
        $runner = new FakeCommandRunner(['-t -f RUNNING general' => "not running\n"]);

        $backend = BackendFactory::forLinux($runner);

        self::assertInstanceOf(WpaCliBackend::class, $backend);
    }

    #[Test]
    public function forLinuxPicksWpaCliWhenNmcliIsMissing(): void
    {
        $runner = new FakeCommandRunner(['-t -f RUNNING general' => ['output' => '', 'exit' => 127]]);

        $backend = BackendFactory::forLinux($runner);

        self::assertInstanceOf(WpaCliBackend::class, $backend);
    }

    #[Test]
    public function forLinuxPicksWpaCliWhenTheRunnerThrows(): void
    {
        $runner = new class implements CommandRunner {
            public function run(Command $command): CommandResult
            {
                throw new RuntimeException('nmcli probe exploded');
            }
        };

        $backend = BackendFactory::forLinux($runner);

        self::assertInstanceOf(WpaCliBackend::class, $backend);
    }

    #[Test]
    public function forLinuxProbesNmcliRunningWithLangC(): void
    {
        $runner = new FakeCommandRunner(['-t -f RUNNING general' => "running\n"]);

        BackendFactory::forLinux($runner);

        $probe = $runner->last();

        self::assertSame('nmcli', $probe->program);
        self::assertSame(['-t', '-f', 'RUNNING', 'general'], $probe->arguments);
        self::assertSame(['LANG' => 'C'], $probe->env);
    }

    #[Test]
    public function forLinuxRunsTheProbeExactlyOnce(): void
    {
        $runner = new FakeCommandRunner(['-t -f RUNNING general' => "running\n"]);

        BackendFactory::forLinux($runner);

        self::assertCount(1, $runner->commands);
    }

    #[Test]
    public function forOsLinuxAlwaysReturnsNmcliRegardlessOfTheProbe(): void
    {
        $runner = new FakeCommandRunner(['-t -f RUNNING general' => "not running\n"]);

        $backend = BackendFactory::forOs(Os::Linux, $runner);

        self::assertInstanceOf(NmcliBackend::class, $backend);
        self::assertCount(0, $runner->commands);
    }
}
