<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exception;

use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\Os;

class CommandFailed extends WiFiException
{
    final public function __construct(
        public readonly Command $command,
        public readonly CommandResult $result,
        string $message
    ) {
        parent::__construct($message);
    }

    public static function fromResult(Command $command, CommandResult $result, Os $os): static
    {
        return new static($command, $result, sprintf(
            'Command %s exited with %d%s',
            $command->toDisplay($os),
            $result->exitCode,
            self::detail($command, $result)
        ));
    }

    /**
     * Some tools (macOS `networksetup -setairportnetwork` among them) exit 0
     * even when they failed, printing the real reason only on stdout/stderr.
     * $result->exitCode is genuinely 0 here — do not fabricate a non-zero
     * one — the message says so plainly instead of implying it came from a
     * non-zero exit.
     */
    public static function despiteZeroExit(Command $command, CommandResult $result, Os $os): static
    {
        return new static($command, $result, sprintf(
            'Command %s exited 0 but reported failure%s',
            $command->toDisplay($os),
            self::detail($command, $result)
        ));
    }

    /**
     * The child's stdout/stderr is normally the most useful part of the
     * message, but for the one command whose stdin carries a secret
     * (`Command::$stdinIsSecret`, e.g. `wpa_cli`'s `set_network … psk …`
     * script) some tools echo back a line derived from what they were just
     * given. This message is written to the journal and, on the
     * provisioning demo, persisted and rendered — so a secret command's
     * captured output is left out entirely here, keeping only the masked
     * command (`$command->toDisplay()` already stars the stdin and any
     * secret argument) and the exit code.
     */
    private static function detail(Command $command, CommandResult $result): string
    {
        if ($command->stdinIsSecret) {
            return '.';
        }

        $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

        return $detail === '' ? '.' : ': ' . $detail;
    }
}
