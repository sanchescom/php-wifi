<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Support;

use LogicException;
use RuntimeException;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\CommandRunner;

/**
 * Maps a substring of Command::describe() to a fixture. The LONGEST matching
 * needle wins ("connection show --active" beats "connection show" for a
 * command that contains both, regardless of which was declared first);
 * declaration order is only a tie-break between equal-length needles. A
 * fixture is either a file path or an array {output: string|path, exit?:
 * int, stderr?: string}. Lines starting with "# SYNTHETIC:" are stripped
 * from file fixtures.
 *
 * Test-only: when $logPath is set, every received Command is appended to it
 * as one raw JSON line, unmasked — including secret arguments in clear
 * text. Never point this at a path outside a test's own temporary storage.
 */
final class FakeCommandRunner implements CommandRunner
{
    /** @var list<Command> */
    public array $commands = [];

    /** @param array<string, string|array{output: string, exit?: int, stderr?: string}> $fixtures */
    public function __construct(private readonly array $fixtures, private readonly ?string $logPath = null)
    {
    }

    public function run(Command $command): CommandResult
    {
        $this->commands[] = $command;

        if ($this->logPath !== null) {
            file_put_contents(
                $this->logPath,
                json_encode([
                    'program' => $command->program,
                    'arguments' => $command->arguments,
                    'env' => $command->env,
                    'secretIndexes' => $command->secretIndexes,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND,
            );
        }

        $described = $command->describe();

        /** @var list<int|string> $needles */
        $needles = array_keys($this->fixtures);
        usort($needles, static fn (int|string $a, int|string $b): int => strlen((string) $b) <=> strlen((string) $a));

        foreach ($needles as $needle) {
            $needleString = (string) $needle;

            if (!str_contains($described, $needleString)) {
                continue;
            }

            $fixture = $this->fixtures[$needle];
            $spec = is_string($fixture) ? ['output' => $fixture] : $fixture;
            $output = is_file($spec['output']) ? (string) file_get_contents($spec['output']) : $spec['output'];
            $output = preg_replace('/^# SYNTHETIC:[^\n]*\n/', '', $output) ?? $output;

            return new CommandResult($spec['exit'] ?? 0, $output, $spec['stderr'] ?? '');
        }

        throw new RuntimeException('No fixture for command: ' . $described);
    }

    public function last(): Command
    {
        if ($this->commands === []) {
            throw new LogicException('No command has been run.');
        }

        return $this->commands[count($this->commands) - 1];
    }
}
