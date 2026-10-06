<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Provision;

use Sanchescom\WiFi\Backend\Linux\ToolPath;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandRunner;

/**
 * The setup hotspot as a QR code a phone's camera joins from, drawn in the
 * terminal by `qrencode` when that is installed. The payload carries the
 * passphrase, so it goes to `qrencode` on stdin, not as an argument.
 */
final class QrCode
{
    /** `WIFI:T:WPA;S:<ssid>;P:<passphrase>;;` with `\`, `;`, `,`, `"` and `:` escaped. */
    public static function payload(string $ssid, string $password): string
    {
        $escape = static fn (string $value): string => addcslashes($value, '\;,":');

        return sprintf('WIFI:T:WPA;S:%s;P:%s;;', $escape($ssid), $escape($password));
    }

    /** The code as text for a terminal, or null when `qrencode` is missing or fails. */
    public static function render(CommandRunner $runner, string $ssid, string $password): ?string
    {
        $qrencode = (new ToolPath($runner))->resolve('qrencode');

        if ($qrencode === null) {
            return null;
        }

        $result = $runner->run(new Command(
            $qrencode,
            ['-t', 'ANSIUTF8', '-o', '-'],
            [],
            [],
            self::payload($ssid, $password),
            true,
        ));

        return $result->isSuccessful() && $result->stdout !== '' ? $result->stdout : null;
    }
}
