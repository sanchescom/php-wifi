<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exception;

use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;

/**
 * The device joined the network — the passphrase was right — and then got
 * no address from it: no DHCP server answered, or it had none left to give.
 */
final class NoAddress extends CommandFailed
{
    public static function forNetwork(string $ssid, Command $command, CommandResult $result): static
    {
        return new static(
            $command,
            $result,
            sprintf('Joined "%s", but the network gave the device no address.', $ssid),
        );
    }
}
