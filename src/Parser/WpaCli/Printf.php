<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\WpaCli;

/**
 * Decodes wpa_supplicant's printf-style escaping of non-printable-ASCII
 * bytes, used for SSIDs in `scan_results`, `list_networks` and `status`
 * alike: `\xNN` for an arbitrary byte, plus `\t` `\n` `\r` `\e` `\"` `\\`.
 *
 * The result is a raw byte string that may or may not be valid UTF-8 (a
 * multi-byte character is spread across several `\xNN` escapes); this class
 * only reverses the escaping, it never validates or transcodes.
 */
final class Printf
{
    public static function decode(string $escaped): string
    {
        $result = '';

        for ($i = 0, $length = strlen($escaped); $i < $length; $i++) {
            $char = $escaped[$i];

            if ($char !== '\\' || $i + 1 >= $length) {
                $result .= $char;

                continue;
            }

            $next = $escaped[$i + 1];

            if ($next === 'x' && $i + 3 < $length && ctype_xdigit($escaped[$i + 2]) && ctype_xdigit($escaped[$i + 3])) {
                $result .= chr((int) hexdec($escaped[$i + 2] . $escaped[$i + 3]));
                $i += 3;

                continue;
            }

            $result .= match ($next) {
                't' => "\t",
                'n' => "\n",
                'r' => "\r",
                'e' => "\x1b",
                '"' => '"',
                '\\' => '\\',
                default => '\\' . $next,
            };

            $i++;
        }

        return $result;
    }
}
