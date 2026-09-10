<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

final readonly class Signal
{
    private function __construct(public float $dbm, public float $quality)
    {
    }

    public static function fromDbm(float $dbm): self
    {
        return new self($dbm, max(0.0, min(100.0, 2 * ($dbm + 100))));
    }

    public static function fromQuality(float $quality): self
    {
        $quality = max(0.0, min(100.0, $quality));

        return new self($quality / 2 - 100, $quality);
    }

    public function isStrongerThan(self $other): bool
    {
        return $this->dbm > $other->dbm;
    }

    public function atLeast(float $dbm): bool
    {
        return $this->dbm >= $dbm;
    }
}
