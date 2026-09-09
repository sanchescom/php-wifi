<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Shell;

enum Os: string
{
    case Linux = 'Linux';
    case Darwin = 'Darwin';
    case Windows = 'Windows';

    public static function current(): self
    {
        return self::from(PHP_OS_FAMILY);
    }

    public function isPosix(): bool
    {
        return $this !== self::Windows;
    }
}
