<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

enum Band: string
{
    case GHz2_4 = '2.4';
    case GHz5 = '5';
    case GHz6 = '6';

    public static function fromFrequency(int $mhz): ?self
    {
        return match (true) {
            $mhz >= 2400 && $mhz < 2500 => self::GHz2_4,
            $mhz >= 4900 && $mhz < 5925 => self::GHz5,
            $mhz >= 5925 => self::GHz6,
            default => null,
        };
    }

    /**
     * The band as system_profiler prints it: "2", "5" or "6" (from
     * "Channel: 157 (5GHz, 80MHz)"), or as Windows 11's `netsh wlan show
     * networks` prints its `Band :` field: "2.4 GHz", "5 GHz", "6 GHz".
     */
    public static function fromHint(?string $hint): ?self
    {
        if ($hint === null) {
            return null;
        }

        return match (trim($hint)) {
            '2', '2.4', '2.4 GHz' => self::GHz2_4,
            '5', '5 GHz' => self::GHz5,
            '6', '6 GHz' => self::GHz6,
            default => null,
        };
    }

    /** Centre frequency of a channel on this band, or null when the channel is not defined on it. */
    public function frequencyForChannel(int $channel): ?int
    {
        return match ($this) {
            self::GHz2_4 => $channel >= 1 && $channel <= 14 ? ($channel === 14 ? 2484 : 2407 + 5 * $channel) : null,
            self::GHz5 => self::fiveGhz($channel),
            self::GHz6 => $channel >= 1 && $channel <= 233 ? 5950 + 5 * $channel : null,
        };
    }

    private static function fiveGhz(int $channel): ?int
    {
        // [first channel, last channel, step] blocks of the 2.x table:
        // 36–64 by 2 (5180 + 5·(ch−36)), 100–144, 149–161, 165–173 by 4
        foreach ([[36, 64, 2], [100, 144, 2], [149, 161, 2], [165, 173, 4]] as [$from, $to, $step]) {
            if ($channel >= $from && $channel <= $to && ($channel - $from) % $step === 0) {
                return 5000 + 5 * $channel;
            }
        }

        return null;
    }
}
