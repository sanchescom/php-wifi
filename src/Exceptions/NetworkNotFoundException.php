<?php

namespace Sanchescom\WiFi\Exceptions;

use RuntimeException;

class NetworkNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Unknown network, try to find another one');
    }
}