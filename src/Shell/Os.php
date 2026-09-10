<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Shell;

use Sanchescom\WiFi\Exception\UnsupportedOperation;
use ValueError;

enum Os: string
{
    case Linux = 'Linux';
    case Darwin = 'Darwin';
    case Windows = 'Windows';

    /** @throws UnsupportedOperation when PHP_OS_FAMILY is not one we support (e.g. "BSD", "Solaris", "Unknown") */
    public static function current(): self
    {
        try {
            return self::from(PHP_OS_FAMILY);
        } catch (ValueError) {
            throw UnsupportedOperation::platform(PHP_OS_FAMILY);
        }
    }

    public function isPosix(): bool
    {
        return $this !== self::Windows;
    }
}
