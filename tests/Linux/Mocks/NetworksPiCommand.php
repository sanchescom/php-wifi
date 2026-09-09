<?php

namespace Sanchescom\WiFi\Test\Linux\Mocks;

use Sanchescom\WiFi\Test\NetworksCommandAbstract;

/**
 * Class NetworksPiCommand.
 */
class NetworksPiCommand extends NetworksCommandAbstract
{
    /** @var string */
    protected static $mock = __DIR__.'/NetworksPi.txt';
}
