<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * End-to-end tests that run `bin/wifi3` as a real subprocess, wired to a
 * FakeCommandRunner via the WIFI_FAKE_RUNNER / WIFI_FAKE_OS test hook.
 */
final class CliTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';

    private const LINUX_FIXTURES = __DIR__ . '/../Fixtures/cli/linux';

    private const DARWIN_FIXTURES = __DIR__ . '/../Fixtures/cli/darwin';

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

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(array $args, string $fixturesDir, string $os): array
    {
        $command = array_merge([PHP_BINARY, self::REPO_ROOT . '/bin/wifi3'], $args);

        $env = [
            'PATH' => (string) getenv('PATH'),
            'WIFI_FAKE_RUNNER' => $fixturesDir,
            'WIFI_FAKE_OS' => $os,
        ];

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::REPO_ROOT,
            $env,
        );

        if ($process === false) {
            throw new RuntimeException('Failed to start bin/wifi3.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
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
