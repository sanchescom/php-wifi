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
        $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

        return new static($command, $result, sprintf(
            'Command %s exited with %d%s',
            $command->toDisplay($os),
            $result->exitCode,
            $detail === '' ? '.' : ': ' . $detail
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
        $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

        return new static($command, $result, sprintf(
            'Command %s exited 0 but reported failure%s',
            $command->toDisplay($os),
            $detail === '' ? '.' : ': ' . $detail
        ));
    }
}
