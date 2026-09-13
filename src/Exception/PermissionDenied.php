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
        $pointer = $os === Os::Linux
            ? ' The process is not allowed to control NetworkManager'
                . ' — see README → Linux → Privileges (polkit).'
            : ' Run the command with sufficient privileges (administrator).';

        return new static($command, $result, $base->getMessage() . $pointer);
    }

    /**
     * `wpa_cli` denies access to wpa_supplicant's control socket with its own
     * "Permission denied" wording, unrelated to the polkit prompt `nmcli`
     * fails with — a distinct named constructor rather than bending
     * {@see self::fromResult()}'s Linux/polkit-specific text to fit it.
     */
    public static function fromWpaCli(Command $command, CommandResult $result): static
    {
        $base = parent::fromResult($command, $result, Os::Linux);
        $pointer = ' The user running this process is not a member of the "netdev" group'
            . ' (or does not have access to the wpa_supplicant control socket)'
            . ' — see README → Linux → Privileges (netdev group).';

        return new static($command, $result, $base->getMessage() . $pointer);
    }
}
