<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Iw;

use Sanchescom\WiFi\Value\Device;

/**
 * Parses `iw dev`: interfaces are listed as `Interface <name>` lines indented
 * under a `phy#N` heading, each followed by indented attribute lines
 * including `type <type>`.
 *
 * A `managed` interface — a real station radio — is preferred whenever one
 * exists: `iw` also lists a `p2p-dev-<name>` companion interface (`type
 * P2P-device`) alongside the real one, and a machine may additionally carry
 * a hostapd access-point interface (`type AP`) or a monitor interface (`type
 * monitor`); none of those is the station interface most callers want, so
 * `managed` wins whenever it is present, regardless of listing order.
 *
 * But when no `managed` interface exists, the first interface that is not
 * `P2P-device` is returned instead — `AP` and `monitor` included. This
 * matters because {@see \Sanchescom\WiFi\Backend\WpaCliBackend} raises its
 * own hotspot through `hostapd`, which switches the radio to `type AP` for
 * as long as the hotspot runs. Measured on real hardware: requiring
 * `managed` unconditionally made `detectDevice()` find nothing while a
 * hotspot this library itself had started was active, so `hotspot stop`
 * failed with "No Wi-Fi device was found on this system" and the hotspot
 * could never be turned off again — the radio was stuck in AP mode
 * permanently. Falling back to the first non-`P2P-device` interface lets the
 * radio still be found — and the hotspot be stopped — while it is serving as
 * an access point. Do not tighten this back to "managed only": that
 * recreates exactly the unstoppable-hotspot defect above. Only
 * `P2P-device` is excluded from the fallback: it is never a usable station
 * or AP interface, only `iw`'s bookkeeping companion for one.
 *
 * `null` is returned only when the output lists no interface at all, or
 * lists nothing but `P2P-device` ones.
 */
final class DevParser
{
    public function parse(string $output): ?Device
    {
        $interfaces = $this->collectInterfaces($output);

        foreach ($interfaces as $interface) {
            if ($interface['type'] === 'managed') {
                return new Device($interface['name']);
            }
        }

        foreach ($interfaces as $interface) {
            if ($interface['type'] !== 'P2P-device') {
                return new Device($interface['name']);
            }
        }

        return null;
    }

    /** @return list<array{name: string, type: string}> */
    private function collectInterfaces(string $output): array
    {
        $interfaces = [];
        $name = null;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^\s*Interface\s+(\S+)/', $line, $matches) === 1) {
                $name = $matches[1];

                continue;
            }

            if ($name === null || preg_match('/^\s*type\s+(\S+)/', $line, $matches) !== 1) {
                continue;
            }

            $interfaces[] = ['name' => $name, 'type' => $matches[1]];
            $name = null;
        }

        return $interfaces;
    }
}
