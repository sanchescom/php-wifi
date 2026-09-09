<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

final readonly class Credentials
{
    private function __construct(public ?string $password)
    {
    }

    public static function password(string $password): self
    {
        return new self($password);
    }

    public static function none(): self
    {
        return new self(null);
    }
}
