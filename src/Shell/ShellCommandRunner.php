<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Shell;

final class ShellCommandRunner implements CommandRunner
{
    public function __construct(private readonly Os $os = Os::Linux)
    {
    }

    public static function forCurrentOs(): self
    {
        return new self(Os::current());
    }

    public function run(Command $command): CommandResult
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command->toShell($this->os), $descriptors, $pipes);

        if (!is_resource($process)) {
            return new CommandResult(127, '', 'Unable to start: ' . $command->describe());
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new CommandResult(proc_close($process), $stdout, $stderr);
    }
}
