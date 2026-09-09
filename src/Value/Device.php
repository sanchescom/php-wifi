<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

use Sanchescom\WiFi\Exception\InvalidArgument;

final readonly class Device
{
    public function __construct(public string $name)
    {
        if (trim($name) === '') {
            throw new InvalidArgument('A device name cannot be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
