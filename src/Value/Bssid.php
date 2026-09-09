<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

use Sanchescom\WiFi\Exception\InvalidArgument;

final readonly class Bssid
{
    private function __construct(public string $value)
    {
    }

    public static function from(string $raw): self
    {
        $normalised = strtolower(trim($raw));

        if (preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $normalised) !== 1) {
            throw new InvalidArgument(sprintf('"%s" is not a BSSID (expected xx:xx:xx:xx:xx:xx).', $raw));
        }

        return new self($normalised);
    }

    /** null when the string is not a BSSID — for parsers, which must not throw on tool output. */
    public static function tryFrom(string $raw): ?self
    {
        try {
            return self::from($raw);
        } catch (InvalidArgument) {
            return null;
        }
    }

    public function equals(self|string $other): bool
    {
        return $this->value === ($other instanceof self ? $other->value : strtolower(trim($other)));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
