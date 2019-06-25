<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exceptions;

use RuntimeException;

/**
 * Class UnknownSystem.
 */
class UnknownSystem extends RuntimeException
{
    /**
     * UnknownSystem constructor.
     */
    public function __construct()
    {
        parent::__construct("Operation system doesn't support: " . PHP_OS);
    }
}
