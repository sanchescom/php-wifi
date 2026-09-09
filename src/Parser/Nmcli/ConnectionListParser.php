<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Nmcli;

use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\KnownNetwork;

/**
 * Parses `nmcli -t -f NAME,TYPE,DEVICE,ACTIVE connection show`, keeping only
 * rows of type `802-11-wireless`.
 */
final class ConnectionListParser
{
    /** @return list<KnownNetwork> */
    public function parse(string $output): array
    {
        $networks = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = $this->fields($line);

            if (count($fields) !== 4) {
                continue;
            }

            [$name, $type, $device, $active] = $fields;

            if ($type !== '802-11-wireless') {
                continue;
            }

            $networks[] = new KnownNetwork(
                name: $name,
                ssid: $name,
                device: $device === '' ? null : new Device($device),
                active: $active === 'yes',
            );
        }

        return $networks;
    }

    /**
     * nmcli escapes a literal ':' inside NAME as '\:', so split on colons
     * that are not preceded by a backslash and un-escape each field.
     *
     * @return list<string>
     */
    private function fields(string $line): array
    {
        $fields = preg_split('/(?<!\\\\):/', $line) ?: [];

        return array_map(
            static fn (string $field): string => trim(str_replace('\\:', ':', $field)),
            $fields,
        );
    }
}
