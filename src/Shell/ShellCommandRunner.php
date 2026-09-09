<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Shell;

use RuntimeException;

/**
 * Runs a Command and captures stdout/stderr separately, never merging them.
 *
 * Two capture strategies, chosen per instance:
 *
 * - **Pipes** (`captureViaFiles: false`): non-blocking pipes drained
 *   concurrently through `stream_select()`. This is the default on POSIX
 *   (Linux, Darwin) and is exercised by every test but the ones that force
 *   the other strategy.
 * - **Files** (`captureViaFiles: true`): stdout and stderr are redirected
 *   straight to two temp files, `proc_close()` waits for the child, then the
 *   files are read and unlinked. This is the default — and the only
 *   supported strategy — on Windows: `stream_set_blocking(false)` is
 *   unsupported on Windows anonymous pipes and `stream_select()` does not
 *   behave like POSIX `select()` against `proc_open()` pipes there (the same
 *   reason Symfony's Process component uses temp files on Windows). It is
 *   deadlock-free by construction, since nothing is read until the child has
 *   already exited.
 *
 * The constructor's `$captureViaFiles` can be forced explicitly (used by
 * tests to exercise the file strategy on a POSIX host); `null` (the default)
 * picks files when the target OS is not POSIX, pipes otherwise.
 */
final class ShellCommandRunner implements CommandRunner
{
    /**
     * A run() that keeps retrying an EINTR-interrupted stream_select() forever
     * would never be wrong on a healthy system, but a bound protects against a
     * genuinely broken descriptor spinning the process — chosen generously
     * high so it is never hit by a real signal storm.
     */
    private const MAX_CONSECUTIVE_SELECT_FAILURES = 1000;

    private readonly Os $os;
    private readonly bool $captureViaFiles;

    public function __construct(?Os $os = null, ?bool $captureViaFiles = null)
    {
        $this->os = $os ?? Os::current();
        $this->captureViaFiles = $captureViaFiles ?? !$this->os->isPosix();
    }

    public static function forCurrentOs(): self
    {
        return new self();
    }

    public function run(Command $command): CommandResult
    {
        return $this->captureViaFiles ? $this->runViaFiles($command) : $this->runViaPipes($command);
    }

    private function runViaPipes(Command $command): CommandResult
    {
        $descriptors = [
            0 => $this->os->isPosix() ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command->toShell($this->os), $descriptors, $pipes);

        if (!is_resource($process)) {
            return new CommandResult(127, '', 'Unable to start: ' . $command->describe());
        }

        if (!$this->os->isPosix()) {
            fclose($pipes[0]);
        }

        // Drain both pipes to EOF (closing them) BEFORE proc_close(): proc_close()
        // blocks until the child exits, and a child blocked writing to a full,
        // unread pipe would never exit — read first, wait second.
        [$stdout, $stderr] = $this->drain($pipes[1], $pipes[2]);

        return new CommandResult(proc_close($process), $stdout, $stderr);
    }

    private function runViaFiles(Command $command): CommandResult
    {
        $stdoutPath = $this->tempFile();
        $stderrPath = $this->tempFile();

        try {
            $descriptors = [
                0 => $this->os->isPosix() ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
                1 => ['file', $stdoutPath, 'w'],
                2 => ['file', $stderrPath, 'w'],
            ];

            $process = proc_open($command->toShell($this->os), $descriptors, $pipes);

            if (!is_resource($process)) {
                return new CommandResult(127, '', 'Unable to start: ' . $command->describe());
            }

            if (!$this->os->isPosix()) {
                fclose($pipes[0]);
            }

            // proc_close() waits for the child to exit; only then are the files
            // complete and safe to read. Nothing is read while the child runs,
            // so this strategy cannot deadlock.
            $exitCode = proc_close($process);
            $stdout = (string) file_get_contents($stdoutPath);
            $stderr = (string) file_get_contents($stderrPath);

            return new CommandResult($exitCode, $stdout, $stderr);
        } finally {
            @unlink($stdoutPath);
            @unlink($stderrPath);
        }
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'php-wifi-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file for output capture.');
        }

        return $path;
    }

    /**
     * Reads stdout and stderr concurrently via stream_select() so a large
     * payload on one pipe can never block forever waiting for the other to
     * close first (proc_open's pipes are fixed-size kernel buffers; reading
     * them sequentially deadlocks the moment the unread pipe fills up while
     * the child is still writing to it).
     *
     * stream_select() returns false and emits an E_WARNING when a signal
     * interrupts the underlying select(2)/poll() call (EINTR) — this is not
     * an error, just "nothing decided, try again"; treating it as EOF (the
     * previous behaviour) silently truncates the read and reports a wrong
     * result. The warning is suppressed with a scoped error handler (a plain
     * @ does not survive PHPUnit's handler, and failOnWarning is on) and a
     * false result retries, bounded by MAX_CONSECUTIVE_SELECT_FAILURES so a
     * descriptor that is genuinely, permanently broken cannot spin forever;
     * the counter resets on every successful select.
     *
     * @param resource $stdout
     * @param resource $stderr
     *
     * @return array{0: string, 1: string} [stdout, stderr]
     */
    private function drain($stdout, $stderr): array
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        /** @var array{1: string, 2: string} $buffers */
        $buffers = [1 => '', 2 => ''];
        /** @var array<1|2, resource> $alive */
        $alive = [1 => $stdout, 2 => $stderr];

        $consecutiveFailures = 0;

        while ($alive !== []) {
            // stream_select() modifies $read in place, preserving keys — dropping
            // only the streams with no activity — so the descriptor (1 or 2) is
            // read straight off the key, with no need to map the stream back.
            $read = $alive;
            $write = null;
            $except = null;

            set_error_handler(static fn (): bool => true);

            try {
                $ready = stream_select($read, $write, $except, null);
            } finally {
                restore_error_handler();
            }

            if ($ready === false) {
                $consecutiveFailures++;

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_SELECT_FAILURES) {
                    break;
                }

                continue;
            }

            $consecutiveFailures = 0;

            foreach ($read as $descriptor => $stream) {
                $chunk = fread($stream, 8192);

                if (is_string($chunk) && $chunk !== '') {
                    $buffers[$descriptor] .= $chunk;
                }

                if (feof($stream)) {
                    unset($alive[$descriptor]);
                }
            }
        }

        fclose($stdout);
        fclose($stderr);

        return [$buffers[1], $buffers[2]];
    }
}
