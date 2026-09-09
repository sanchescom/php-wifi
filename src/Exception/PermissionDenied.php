<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exception;

use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\Os;

final class PermissionDenied extends CommandFailed
{
    public static function looksLike(CommandResult $result): bool
    {
        return preg_match('/Not authorized|Insufficient privileges/i', $result->stderr . "\n" . $result->stdout) === 1;
    }

    public static function fromResult(Command $command, CommandResult $result, Os $os): static
    {
        $base = parent::fromResult($command, $result, $os);
        $pointer = ' The process is not allowed to control NetworkManager'
            . ' — see README → Linux → Privileges (polkit).';

        return new static($command, $result, $base->getMessage() . $pointer);
    }
}
