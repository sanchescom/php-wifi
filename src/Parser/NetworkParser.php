<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Parser;

use Sanchescom\WiFi\Value\Network;

interface NetworkParser
{
    /** @return list<Network> */
    public function parse(string $output): array;
}
