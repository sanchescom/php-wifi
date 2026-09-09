<?php

namespace Sanchescom\WiFi\Test\Linux\Mocks;

use Sanchescom\WiFi\Test\NetworksCommandAbstract;

/**
 * Class NetworksCommand.
 */
class NetworksCommand extends NetworksCommandAbstract
{
    /** @var string */
    protected static $mock = __DIR__.'/../../Fixtures/linux/Networks.txt';

    /** @var array<string, string> */
    protected static array $mocks = ['-f DEVICE,TYPE device' => __DIR__.'/../../Fixtures/linux/Devices.txt'];
}
