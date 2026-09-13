<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Iw;

/** Parses `iw dev <iface> station dump`, counting connected stations. */
final class StationDumpParser
{
    public function count(string $output): int
    {
        $stations = 0;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (str_starts_with($line, 'Station ')) {
                $stations++;
            }
        }

        return $stations;
    }
}
