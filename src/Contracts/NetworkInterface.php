<?php

namespace Sanchescom\WiFi\Contracts;

use Sanchescom\WiFi\System\AbstractNetwork;

/**
 * Interface NetworkInterface.
 */
interface NetworkInterface
{
    /**
     * @param string $password
     * @param string|null $device
     */
    public function connect(string $password, ?string $device = null): void;

    /**
     * @param string|null $device
     */
    public function disconnect(?string $device = null): void;

    /**
     * @param array<int, string> $network
     *
     * @return \Sanchescom\WiFi\System\AbstractNetwork
     */
    public function createFromArray(array $network): AbstractNetwork;
}
