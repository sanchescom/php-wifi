<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Support;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sanchescom\WiFi\Shell\Command;

final class FakeCommandRunnerTest extends TestCase
{
    #[Test]
    public function the_longest_needle_wins_even_when_declared_before_a_shorter_one(): void
    {
        $runner = new FakeCommandRunner([
            'connection show' => 'short',
            'connection show --active' => 'long',
        ]);

        $result = $runner->run(new Command('nmcli', ['-t', '-f', 'NAME', 'connection', 'show', '--active']));

        $this->assertSame('long', $result->stdout);
    }

    #[Test]
    public function a_literal_string_fixture_is_returned_verbatim(): void
    {
        $runner = new FakeCommandRunner(['device wifi list' => 'DEVICE  SSID']);

        $result = $runner->run(new Command('nmcli', ['device', 'wifi', 'list']));

        $this->assertSame('DEVICE  SSID', $result->stdout);
        $this->assertSame(0, $result->exitCode);
        $this->assertSame('', $result->stderr);
    }

    #[Test]
    public function a_file_fixture_strips_its_synthetic_header(): void
    {
        $runner = new FakeCommandRunner([
            'connection show' => __DIR__ . '/Fixtures/connection-show.txt',
        ]);

        $result = $runner->run(new Command('nmcli', ['connection', 'show']));

        $this->assertSame("Home:802-11-wireless:wlan0:yes\n", $result->stdout);
        $this->assertStringNotContainsString('SYNTHETIC', $result->stdout);
    }

    #[Test]
    public function the_output_exit_stderr_array_form_is_honoured(): void
    {
        $runner = new FakeCommandRunner([
            'device wifi connect' => ['output' => '', 'exit' => 1, 'stderr' => 'Error: Not authorized to control networking.'],
        ]);

        $result = $runner->run(new Command('nmcli', ['device', 'wifi', 'connect', 'Home']));

        $this->assertSame(1, $result->exitCode);
        $this->assertSame('', $result->stdout);
        $this->assertSame('Error: Not authorized to control networking.', $result->stderr);
    }

    #[Test]
    public function no_matching_fixture_throws(): void
    {
        $runner = new FakeCommandRunner(['device wifi list' => 'irrelevant']);

        $this->expectException(RuntimeException::class);

        $runner->run(new Command('nmcli', ['connection', 'show']));
    }

    #[Test]
    public function last_on_an_empty_history_throws_a_logic_exception(): void
    {
        $runner = new FakeCommandRunner([]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No command has been run.');

        $runner->last();
    }

    #[Test]
    public function a_numeric_looking_needle_does_not_type_error(): void
    {
        $runner = new FakeCommandRunner(['123' => 'matched']);

        $result = $runner->run(new Command('nmcli', ['-t', '-f', '123']));

        $this->assertSame('matched', $result->stdout);
    }

    #[Test]
    public function it_appends_one_raw_json_line_per_command(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'php-wifi-log-');
        self::assertIsString($log);

        $runner = new FakeCommandRunner(['device wifi list' => ''], $log);
        $runner->run(new Command('nmcli', ['device', 'wifi', 'list'], ['LANG' => 'C']));

        try {
            // No fixture matches this command; the point of this test is that
            // it is logged anyway, before the "no fixture" exception fires.
            $runner->run(new Command('nmcli', ['device', 'wifi', 'connect', 'x', 'password', 'hunter2 '], [], [5]));
        } catch (RuntimeException) {
        }

        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        unlink($log);

        $this->assertCount(2, $lines);

        $second = json_decode($lines[1], true);
        $this->assertSame('nmcli', $second['program']);
        $this->assertSame('hunter2 ', $second['arguments'][5]);
        $this->assertSame([5], $second['secretIndexes']);
    }
}
