<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Shell;

final class ShellCommandRunner implements CommandRunner
{
    private readonly Os $os;

    public function __construct(?Os $os = null)
    {
        $this->os = $os ?? Os::current();
    }

    public static function forCurrentOs(): self
    {
        return new self();
    }

    public function run(Command $command): CommandResult
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

    /**
     * Reads stdout and stderr concurrently via stream_select() so a large
     * payload on one pipe can never block forever waiting for the other to
     * close first (proc_open's pipes are fixed-size kernel buffers; reading
     * them sequentially deadlocks the moment the unread pipe fills up while
     * the child is still writing to it).
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

        while ($alive !== []) {
            // stream_select() modifies $read in place, preserving keys — dropping
            // only the streams with no activity — so the descriptor (1 or 2) is
            // read straight off the key, with no need to map the stream back.
            $read = $alive;
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, null) === false) {
                break;
            }

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
