<?php

namespace Sanchescom\Wifi\System;

trait Separable
{
    /** @var string */
    protected $separator = '--separator--';

    /**
     * @param string $output
     *
     * @return array
     */
    protected function explodeOutput(string $output): array
    {
        return explode($this->separator, $output);
    }
}
