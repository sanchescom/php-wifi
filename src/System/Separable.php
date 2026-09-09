<?php

namespace Sanchescom\WiFi\System;

trait Separable
{
    /** @var string */
    protected $separator = '--separator--';

    /**
     * @param string $output
     *
     * @return array<int, string>
     */
    protected function explodeOutput(string $output): array
    {
        return explode($this->separator, $output);
    }
}
