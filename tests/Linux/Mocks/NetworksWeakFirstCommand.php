<?php

namespace Sanchescom\WiFi\Test\Linux\Mocks;

use Sanchescom\WiFi\Test\NetworksCommandAbstract;

/**
 * Class NetworksWeakFirstCommand.
 */
class NetworksWeakFirstCommand extends NetworksCommandAbstract
{
    /** @var string */
    protected static $mock = __DIR__.'/../../Fixtures/linux/NetworksWeakFirst.txt';
}
