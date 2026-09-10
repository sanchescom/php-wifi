<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser\Nmcli;

/**
 * Splits one `nmcli --terse` line into its colon-separated fields.
 *
 * nmcli escapes a literal ':' as '\:' and a literal '\' as '\\'. A regex
 * lookbehind of fixed width cannot tell those apart (a field ending in an
 * escaped backslash makes the real separator that follows look escaped), so
 * this walks the line once instead: a backslash followed by any character
 * emits that character literally, an unescaped ':' ends a field, and a
 * trailing lone backslash is kept literally.
 */
final class TerseLine
{
    /** @return list<string> */
    public static function split(string $line): array
    {
        $fields = [];
        $field = '';
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $field .= $line[$i + 1];
                $i++;
                continue;
            }

            if ($char === ':') {
                $fields[] = $field;
                $field = '';
                continue;
            }

            $field .= $char;
        }

        $fields[] = $field;

        return $fields;
    }
}
