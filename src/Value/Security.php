<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

enum Security: string
{
    case WPA3 = 'WPA3';
    case WPA2 = 'WPA2';
    case WPA = 'WPA';
    case WEP = 'WEP';
    case Open = 'Open';
    case Unknown = 'Unknown';

    /** Ordered substring match on what the tool printed; open networks print '', '--' or '(none)'. */
    public static function fromDescription(string $raw): self
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === '--' || $raw === '(none)') {
            return self::Open;
        }

        foreach ([self::WPA3, self::WPA2, self::WPA, self::WEP] as $candidate) {
            if (str_contains($raw, $candidate->value)) {
                return $candidate;
            }
        }

        return self::Unknown;
    }

    /** Basename (without .xml) of the netsh profile template in templates/. */
    public function windowsProfileTemplate(): string
    {
        return match ($this) {
            self::WPA3, self::WPA2, self::WPA, self::WEP => $this->value,
            self::Open, self::Unknown => 'Unknown',
        };
    }
}
