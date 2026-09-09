<?php

namespace Sanchescom\WiFi\Test\Darwin\Mocks;

use Sanchescom\WiFi\Test\NetworksCommandAbstract;

/**
 * Class NetworksCommand.
 */
class NetworksCommand extends NetworksCommandAbstract
{
    /** @var string */
    protected static $mock = __DIR__.'/Networks.txt';

    /** @var array<string, string> */
    protected static array $mocks = ['listallhardwareports' => __DIR__.'/HardwarePorts.txt'];
}
