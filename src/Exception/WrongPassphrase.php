<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exception;

use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;

/**
 * The network was there and refused the passphrase. Neither Linux tool says
 * so in as many words — NetworkManager asks for the secret again until
 * `nmcli` times out, `wpa_supplicant` disables the network block for a while
 * after a failed handshake — so the backends read those signs and say it
 * here. The message carries neither the command nor its output.
 */
final class WrongPassphrase extends CommandFailed
{
    public static function forNetwork(string $ssid, Command $command, CommandResult $result): static
    {
        return new static($command, $result, sprintf('The passphrase for "%s" was not accepted.', $ssid));
    }
}
