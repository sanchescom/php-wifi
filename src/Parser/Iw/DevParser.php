<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Iw;

use Sanchescom\WiFi\Value\Device;

/**
 * Parses `iw dev`: interfaces are listed as `Interface <name>` lines indented
 * under a `phy#N` heading, each followed by indented attribute lines
 * including `type <type>`. Returns the first interface whose type is
 * `managed` — `iw` also lists a `p2p-dev-<name>` companion interface
 * (`type P2P-device`) alongside the real one, and a machine may additionally
 * carry a hostapd access-point interface (`type AP`) or a monitor interface
 * (`type monitor`); none of those is the station interface this backend
 * needs, so only `managed` qualifies.
 */
final class DevParser
{
    public function parse(string $output): ?Device
    {
        $name = null;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^\s*Interface\s+(\S+)/', $line, $matches) === 1) {
                $name = $matches[1];

                continue;
            }

            if ($name === null || preg_match('/^\s*type\s+(\S+)/', $line, $matches) !== 1) {
                continue;
            }

            if ($matches[1] === 'managed') {
                return new Device($name);
            }

            $name = null;
        }

        return null;
    }
}
