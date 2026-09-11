<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * End-to-end tests that run `bin/wifi` as a real subprocess, wired to a
 * FakeCommandRunner via the WIFI_FAKE_RUNNER / WIFI_FAKE_OS test hook.
 */
final class CliTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';

    private const LINUX_FIXTURES = __DIR__ . '/../Fixtures/cli/linux';

    private const DARWIN_FIXTURES = __DIR__ . '/../Fixtures/cli/darwin';

    private const LINUX_CONNECTED_FIXTURES = __DIR__ . '/../Fixtures/cli/linux-connected';

    private const LINUX_HOTSPOT_INACTIVE_FIXTURES = __DIR__ . '/../Fixtures/cli/linux-hotspot-inactive';

    private const LINUX_HOTSPOT_STOP_FIXTURES = __DIR__ . '/../Fixtures/cli/linux-hotspot-stop';

    #[Test]
    public function list_unique_shows_four_rows_with_the_strongest_bell340_first(): void
    {
        $result = $this->runCli(['list', '--unique'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);

        $lines = $this->lines($result['stdout']);

        $this->assertStringContainsString('SSID', $lines[0]);

        $dataLines = $this->tableDataLines($lines);

        $this->assertCount(4, $dataLines);
        $this->assertStringContainsString('BELL340', $dataLines[0]);
    }

    #[Test]
    public function list_hints_about_hidden_ssids_on_stderr(): void
    {
        $result = $this->runCli(['list'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('hidden', $result['stderr']);
    }

    #[Test]
    public function list_hints_about_hidden_ssids_with_linux_wording_not_macos(): void
    {
        $result = $this->runCli(['list'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString(
            '1 network(s) broadcast no SSID (hidden); they are shown as "-".',
            $result['stderr'],
        );
        $this->assertStringNotContainsString('macOS', $result['stderr']);
    }

    #[Test]
    public function list_json_prints_one_object_per_network(): void
    {
        $result = $this->runCli(['list', '--unique', '--json'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);

        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(4, $rows);
        $this->assertSame('BELL340', $rows[0]['ssid']);
        $this->assertSame('2.4', $rows[0]['band']);
        $this->assertSame(2412, $rows[0]['frequency']);
        $this->assertFalse($rows[0]['connected']);
        $this->assertArrayHasKey('securityFlags', $rows[0]);
    }

    #[Test]
    public function list_json_marks_a_hidden_network_row(): void
    {
        $result = $this->runCli(['list', '--json'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);

        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $hidden = array_values(array_filter($rows, static fn (array $row): bool => $row['ssid'] === ''));

        $this->assertCount(1, $hidden);
        $this->assertTrue($hidden[0]['hidden']);
    }

    #[Test]
    public function list_json_uses_null_for_fields_the_platform_does_not_report(): void
    {
        // On macOS, system_profiler never reports a BSSID, and a network
        // with no "Signal / Noise" line yields no Signal value either.
        $result = $this->runCli(['list', '--json'], self::DARWIN_FIXTURES, 'Darwin');

        $this->assertSame(0, $result['exit'], $result['stderr']);

        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $offshore = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['ssid'] === 'Offshore View Marine Services',
        ));

        $this->assertCount(1, $offshore);
        $this->assertNull($offshore[0]['bssid']);
        $this->assertNull($offshore[0]['quality']);
        $this->assertNull($offshore[0]['dbm']);
    }

    #[Test]
    public function list_connected_json_prints_an_empty_array_when_nothing_is_connected(): void
    {
        $result = $this->runCli(['list', '--connected', '--json'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('[]', trim($result['stdout']));
    }

    #[Test]
    public function hotspot_start_with_a_short_password_fails_validation_on_linux(): void
    {
        $result = $this->runCli(
            ['hotspot', 'start', '--ssid=x', '--password=short'],
            self::LINUX_FIXTURES,
            'Linux',
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('passphrase must be 8', $result['stderr']);
    }

    #[Test]
    public function hotspot_start_is_unsupported_on_darwin(): void
    {
        $result = $this->runCli(
            ['hotspot', 'start', '--ssid=x', '--password=short'],
            self::DARWIN_FIXTURES,
            'Darwin',
        );

        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('does not support', $result['stderr']);
    }

    #[Test]
    public function hotspot_start_marks_an_auto_detected_device(): void
    {
        $result = $this->runCli(
            ['hotspot', 'start', '--ssid=femus-setup', '--password=password1'],
            self::LINUX_FIXTURES,
            'Linux',
        );

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertMatchesRegularExpression(
            '/^Hotspot femus-setup started on wlan0 \(auto\)$/m',
            $result['stdout'],
        );
    }

    #[Test]
    public function hotspot_start_does_not_mark_an_explicit_device(): void
    {
        $result = $this->runCli(
            ['hotspot', 'start', '--ssid=femus-setup', '--password=password1', '--device=wlan0'],
            self::LINUX_FIXTURES,
            'Linux',
        );

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertMatchesRegularExpression('/^Hotspot femus-setup started on wlan0$/m', $result['stdout']);
    }

    #[Test]
    public function device_prints_the_detected_wifi_device(): void
    {
        $result = $this->runCli(['device'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('wlan0', trim($result['stdout']));
    }

    #[Test]
    public function known_lists_the_saved_wireless_networks(): void
    {
        $result = $this->runCli(['known'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);

        $dataLines = $this->tableDataLines($this->lines($result['stdout']));

        $this->assertCount(3, $dataLines);
    }

    #[Test]
    public function forget_confirms_on_stdout(): void
    {
        $result = $this->runCli(['forget', 'BELL340'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('Forgot BELL340.', trim($result['stdout']));
    }

    #[Test]
    public function disconnect_reports_permission_denied_as_exit_three(): void
    {
        $result = $this->runCli(['disconnect'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(3, $result['exit']);
        $this->assertStringContainsString('polkit', $result['stderr']);
    }

    #[Test]
    public function connect_without_ssid_or_bssid_fails_with_a_usage_error(): void
    {
        $result = $this->runCli(['connect'], self::LINUX_FIXTURES, 'Linux');

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('--ssid or --bssid', $result['stderr']);
    }

    #[Test]
    public function list_connected_shows_only_the_active_network(): void
    {
        $result = $this->runCli(['list', '--connected'], self::LINUX_CONNECTED_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);

        $dataLines = $this->tableDataLines($this->lines($result['stdout']));

        $this->assertCount(1, $dataLines);
        $this->assertStringContainsString('AlphaNet-foiEmE', $dataLines[0]);
        $this->assertStringContainsString('true', $dataLines[0]);
    }

    #[Test]
    public function hotspot_status_is_inactive_when_no_connection_is_active(): void
    {
        $result = $this->runCli(['hotspot', 'status'], self::LINUX_HOTSPOT_INACTIVE_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('inactive', trim($result['stdout']));
    }

    #[Test]
    public function hotspot_stop_confirms_on_stdout(): void
    {
        $result = $this->runCli(['hotspot', 'stop'], self::LINUX_HOTSPOT_STOP_FIXTURES, 'Linux');

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('Hotspot stopped.', trim($result['stdout']));
    }

    #[Test]
    public function known_is_unsupported_on_darwin(): void
    {
        $result = $this->runCli(['known'], self::DARWIN_FIXTURES, 'Darwin');

        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('does not support', $result['stderr']);
    }

    #[Test]
    public function hotspot_start_without_ssid_fails_with_a_usage_message_not_the_hotspot_config_message(): void
    {
        $result = $this->runCli(
            ['hotspot', 'start', '--password=password1'],
            self::LINUX_HOTSPOT_INACTIVE_FIXTURES,
            'Linux',
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('--ssid is required', $result['stderr']);
        $this->assertStringNotContainsString('1–32 bytes', $result['stderr']);
    }

    #[Test]
    public function connect_reads_the_password_from_a_file_verbatim(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'php-wifi-pass-');
        self::assertIsString($file);
        $log = tempnam(sys_get_temp_dir(), 'php-wifi-log-');
        self::assertIsString($log);

        try {
            file_put_contents($file, "p w \n"); // trailing space kept, one newline stripped

            $result = $this->runCli(
                ['connect', '--ssid=BELL340', '--password-file=' . $file],
                self::LINUX_FIXTURES,
                'Linux',
                ['WIFI_FAKE_LOG' => $log],
            );

            $this->assertSame(0, $result['exit'], $result['stderr']);
            $connect = $this->lastLoggedCommand($log, 'device wifi connect');
            $this->assertSame('p w ', $connect['arguments'][7]);
        } finally {
            unlink($file);
            unlink($log);
        }
    }

    #[Test]
    public function connect_reads_the_password_from_stdin(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'php-wifi-log-');
        self::assertIsString($log);

        try {
            $result = $this->runCli(
                ['connect', '--ssid=BELL340', '--password-file=-'],
                self::LINUX_FIXTURES,
                'Linux',
                ['WIFI_FAKE_LOG' => $log],
                'p w',
            );

            $this->assertSame(0, $result['exit'], $result['stderr']);
            $connect = $this->lastLoggedCommand($log, 'device wifi connect');
            $this->assertSame('p w', $connect['arguments'][7]);
        } finally {
            unlink($log);
        }
    }

    #[Test]
    public function password_and_password_file_together_are_rejected(): void
    {
        $result = $this->runCli(
            ['connect', '--ssid=x', '--password=a', '--password-file=/dev/null'],
            self::LINUX_FIXTURES,
            'Linux',
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('not both', $result['stderr']);
    }

    #[Test]
    public function a_missing_password_file_is_rejected(): void
    {
        $missing = sys_get_temp_dir() . '/php-wifi-missing-' . bin2hex(random_bytes(8));

        $result = $this->runCli(
            ['connect', '--ssid=x', '--password-file=' . $missing],
            self::LINUX_FIXTURES,
            'Linux',
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString($missing, $result['stderr']);
    }

    #[Test]
    public function an_empty_password_file_is_rejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'php-wifi-pass-');
        self::assertIsString($file);

        try {
            $result = $this->runCli(
                ['connect', '--ssid=x', '--password-file=' . $file],
                self::LINUX_FIXTURES,
                'Linux',
            );

            $this->assertSame(1, $result['exit']);
            $this->assertStringContainsString('is empty', $result['stderr']);
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function an_empty_password_file_path_is_rejected_without_the_raw_engine_message(): void
    {
        $result = $this->runCli(
            ['connect', '--ssid=x', '--password-file='],
            self::LINUX_FIXTURES,
            'Linux',
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('--password-file needs a path', $result['stderr']);
        $this->assertStringNotContainsString('Path cannot be empty', $result['stderr']);
    }

    #[Test]
    public function a_directory_given_as_the_password_file_is_rejected(): void
    {
        $result = $this->runCli(
            ['connect', '--ssid=x', '--password-file=' . sys_get_temp_dir()],
            self::LINUX_FIXTURES,
            'Linux',
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('is a directory', $result['stderr']);
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env extra environment entries merged over the base test env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(array $args, string $fixturesDir, string $os, array $env = [], ?string $stdin = null): array
    {
        $command = array_merge([PHP_BINARY, self::REPO_ROOT . '/bin/wifi'], $args);

        $env = array_merge(
            [
                'PATH' => (string) getenv('PATH'),
                'WIFI_FAKE_RUNNER' => $fixturesDir,
                'WIFI_FAKE_OS' => $os,
            ],
            $env,
        );

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::REPO_ROOT,
            $env,
        );

        if ($process === false) {
            throw new RuntimeException('Failed to start bin/wifi.');
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Reads the JSON lines FakeCommandRunner appended to $log and returns the
     * last one whose rendered arguments contain $needle, decoded as an array.
     *
     * @return array{program: string, arguments: list<mixed>, env: array<string, string>, secretIndexes: list<int>}
     */
    private function lastLoggedCommand(string $log, string $needle): array
    {
        $lines = $this->lines((string) file_get_contents($log));

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            /**
             * @var array{
             *     program: string,
             *     arguments: list<mixed>,
             *     env: array<string, string>,
             *     secretIndexes: list<int>,
             * } $decoded
             */
            $decoded = json_decode($lines[$i], true);
            $rendered = implode(' ', array_map(static fn (mixed $argument): string => (string) $argument, $decoded['arguments']));

            if (str_contains($rendered, $needle)) {
                return $decoded;
            }
        }

        throw new RuntimeException(sprintf('No logged command in "%s" contains "%s".', $log, $needle));
    }

    /** @return list<string> */
    private function lines(string $output): array
    {
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * Drops the header line and the ConsoleTable separator line
     * (`hideBorder()` still prints one dashes-only line under the header),
     * leaving exactly the data rows.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function tableDataLines(array $lines): array
    {
        $body = array_slice($lines, 1);

        return array_values(array_filter($body, static fn (string $line): bool => !preg_match('/^-+$/', $line)));
    }
}
