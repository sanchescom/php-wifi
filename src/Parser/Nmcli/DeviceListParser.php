<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Nmcli;

use Sanchescom\WiFi\Value\Device;

/**
 * Parses `nmcli -t -f DEVICE,TYPE device`.
 */
final class DeviceListParser
{
    public function parse(string $output): ?Device
    {
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = $this->fields($line);

            if (count($fields) < 2) {
                continue;
            }

            [$device, $type] = $fields;

            if ($type === 'wifi' && $device !== '') {
                return new Device($device);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function fields(string $line): array
    {
        $fields = preg_split('/(?<!\\\\):/', $line) ?: [];

        return array_map(
            static fn (string $field): string => trim(str_replace('\\:', ':', $field)),
            $fields,
        );
    }
}
