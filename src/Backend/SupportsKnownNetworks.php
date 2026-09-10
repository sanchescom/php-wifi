<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Value\KnownNetwork;

interface SupportsKnownNetworks
{
    /** @return list<KnownNetwork> */
    public function knownNetworks(): array;

    public function forget(string $ssidOrName): void;
}
