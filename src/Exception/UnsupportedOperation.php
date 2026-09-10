<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exception;

final class UnsupportedOperation extends WiFiException
{
    public static function by(string $backendClass, string $capabilityInterface): self
    {
        return new self(sprintf(
            '%s does not support %s.',
            self::shortName($backendClass),
            self::shortName($capabilityInterface),
        ));
    }

    public static function platform(string $family): self
    {
        return new self(sprintf('Unsupported operating system family "%s".', $family));
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
