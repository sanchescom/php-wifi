<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Provision;

use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Backend\Linux\RuntimeDirectory;
use Sanchescom\WiFi\Backend\NmcliBackend;
use Sanchescom\WiFi\Provision\State;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Test\Support\TestClock;
use Sanchescom\WiFi\WiFi;

/** A runtime directory of the test's own, and a WiFi facade on NmcliBackend driven by fixtures. */
abstract class ProvisionTestCase extends TestCase
{
    protected const FIXTURES = __DIR__ . '/../Fixtures/linux';

    protected string $runtimeDir;

    protected TestClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeDir = sys_get_temp_dir() . '/php-wifi-provision-test-' . bin2hex(random_bytes(8));
        mkdir($this->runtimeDir, 0700);
        $this->clock = new TestClock(1_000);
    }

    protected function tearDown(): void
    {
        chmod($this->runtimeDir, 0700);
        array_map(unlink(...), glob($this->runtimeDir . '/*') ?: []);
        rmdir($this->runtimeDir);
        parent::tearDown();
    }

    protected function state(): State
    {
        return new State(new RuntimeDirectory($this->runtimeDir), $this->clock);
    }

    /** @param array<string, mixed> $fixtures laid over a device with no hotspot up and the stock scan */
    protected function runner(array $fixtures = []): FakeCommandRunner
    {
        return new FakeCommandRunner(array_merge([
            'connection show --active' => '',
            '-f NAME,UUID,TYPE connection show' => '',
            '-f DEVICE,TYPE device' => self::FIXTURES . '/Devices.txt',
            'device wifi list' => self::FIXTURES . '/Networks.txt',
            'device wifi connect' => '',
            'connection down' => '',
            'connection add' => '',
            'connection up' => '',
        ], $fixtures));
    }

    protected function wifi(FakeCommandRunner $runner): WiFi
    {
        return new WiFi(new NmcliBackend($runner, $this->runtimeDir));
    }

    /** @return list<string> every command run so far, as text */
    protected static function commands(FakeCommandRunner $runner): array
    {
        return array_map(static fn ($command): string => $command->describe(), $runner->commands);
    }
}
