<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Iw;

use Sanchescom\WiFi\Value\Device;

/**
 * Parses `iw dev`: interfaces are listed as `Interface <name>` lines indented
 * under a `phy#N` heading, each followed by indented attribute lines
 * including `type <type>`. Returns the first interface whose type is not
 * `P2P-device` — `iw` always lists a `p2p-dev-<name>` companion interface
 * alongside the real one, which this backend has no use for.
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

            if ($matches[1] !== 'P2P-device') {
                return new Device($name);
            }

            $name = null;
        }

        return null;
    }
}
