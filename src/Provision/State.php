<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Provision;

use RuntimeException;
use Sanchescom\WiFi\Backend\Linux\RuntimeDirectory;
use Sanchescom\WiFi\Watchdog\Clock;
use Sanchescom\WiFi\Watchdog\SystemClock;

/**
 * What a provisioning run has to remember between processes: the web server
 * that takes the form and the supervisor that keeps the hotspot up are two of
 * them, and the phone loses the page the moment a join starts.
 *
 * Three small files in {@see RuntimeDirectory}: the networks scanned before
 * the hotspot came up (a single radio cannot scan while it is an access
 * point), a marker that says a join is running, and the outcome of the last
 * attempt. None of them ever holds a passphrase. Nothing is read from, or
 * written to, a directory that is not safe; a failed write is not an error —
 * the run goes on without that piece of memory.
 */
final class State
{
    /** A joining marker older than this belongs to an attempt that died, not to one still running. */
    private const JOINING_MAX_AGE = 120;

    public function __construct(
        private readonly RuntimeDirectory $directory = new RuntimeDirectory(),
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function reset(): void
    {
        foreach (['networks.json', 'joining', 'attempt.json'] as $name) {
            $this->remove($name);
        }
    }

    /** @param list<array{ssid: string, band: ?string, quality: ?int, security: string}> $networks */
    public function cacheNetworks(array $networks): void
    {
        $this->write('networks.json', (string) json_encode($networks));
    }

    /** @return list<array{ssid: string, band: ?string, quality: ?int, security: string}>|null null when nothing usable is cached */
    public function cachedNetworks(): ?array
    {
        $decoded = json_decode($this->read('networks.json') ?? '', true);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }

        $networks = [];

        foreach ($decoded as $row) {
            if (!is_array($row) || !is_string($row['ssid'] ?? null) || !is_string($row['security'] ?? null)) {
                return null;
            }

            $networks[] = [
                'ssid' => $row['ssid'],
                'band' => is_string($row['band'] ?? null) ? $row['band'] : null,
                'quality' => is_int($row['quality'] ?? null) ? $row['quality'] : null,
                'security' => $row['security'],
            ];
        }

        return $networks;
    }

    public function beginAttempt(): void
    {
        $this->write('joining', (string) $this->clock->now());
    }

    public function endAttempt(string $ssid, bool $ok, ?string $reason, ?string $message): void
    {
        $this->write('attempt.json', (string) json_encode([
            'at' => $this->clock->now(),
            'ssid' => $ssid,
            'ok' => $ok,
            'reason' => $reason,
            'message' => $message,
        ]));
        $this->remove('joining');
    }

    public function isJoining(): bool
    {
        $startedAt = $this->read('joining');

        if ($startedAt === null || !ctype_digit($startedAt)) {
            return false;
        }

        $age = $this->clock->now() - (int) $startedAt;

        // A negative age is a clock that stepped back (a Pi without an RTC); the attempt is still recent.
        return $age < self::JOINING_MAX_AGE;
    }

    /** @return array{at: int, ssid: string, ok: bool, reason: ?string, message: ?string}|null */
    public function lastAttempt(): ?array
    {
        $decoded = json_decode($this->read('attempt.json') ?? '', true);

        if (
            !is_array($decoded)
            || !is_int($decoded['at'] ?? null)
            || !is_string($decoded['ssid'] ?? null)
            || !is_bool($decoded['ok'] ?? null)
        ) {
            return null;
        }

        return [
            'at' => $decoded['at'],
            'ssid' => $decoded['ssid'],
            'ok' => $decoded['ok'],
            'reason' => is_string($decoded['reason'] ?? null) ? $decoded['reason'] : null,
            'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : null,
        ];
    }

    /** The network the device joined in this run, or null while it has not. */
    public function joinedSsid(): ?string
    {
        $attempt = $this->lastAttempt();

        return $attempt !== null && $attempt['ok'] ? $attempt['ssid'] : null;
    }

    private function path(string $name): string
    {
        return $this->directory->path() . '/provision-' . $name;
    }

    private function write(string $name, string $contents): void
    {
        try {
            $this->directory->ensure();
        } catch (RuntimeException) {
            return;
        }

        @file_put_contents($this->path($name), $contents);
    }

    private function read(string $name): ?string
    {
        $path = $this->path($name);

        if (!$this->directory->isSafe() || !is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function remove(string $name): void
    {
        $path = $this->path($name);

        if ($this->directory->isSafe() && is_file($path)) {
            @unlink($path);
        }
    }
}
