<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Support;

use RuntimeException;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\CommandRunner;

/**
 * Maps a substring of Command::describe() to a fixture. Checked in
 * declaration order; first match wins — so list the MOST SPECIFIC needle
 * first ("connection show --active" before "connection show"; never a bare
 * "connect", which also matches "connection …"). A fixture is either a file
 * path or an array {output: string|path, exit?: int, stderr?: string}. Lines
 * starting with "# SYNTHETIC:" are stripped from file fixtures.
 */
final class FakeCommandRunner implements CommandRunner
{
    /** @var list<Command> */
    public array $commands = [];

    /** @param array<string, string|array{output: string, exit?: int, stderr?: string}> $fixtures */
    public function __construct(private readonly array $fixtures)
    {
    }

    public function run(Command $command): CommandResult
    {
        $this->commands[] = $command;
        $described = $command->describe();

        foreach ($this->fixtures as $needle => $fixture) {
            if (!str_contains($described, $needle)) {
                continue;
            }
            $spec = is_string($fixture) ? ['output' => $fixture] : $fixture;
            $output = is_file($spec['output']) ? (string) file_get_contents($spec['output']) : $spec['output'];
            $output = preg_replace('/^# SYNTHETIC:[^\n]*\n/', '', $output) ?? $output;

            return new CommandResult($spec['exit'] ?? 0, $output, $spec['stderr'] ?? '');
        }

        throw new RuntimeException('No fixture for command: ' . $described);
    }

    public function last(): Command
    {
        return $this->commands[count($this->commands) - 1];
    }
}
