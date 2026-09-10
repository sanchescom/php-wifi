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
}
