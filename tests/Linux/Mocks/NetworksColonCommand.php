<?php

namespace Sanchescom\WiFi\Test\Linux\Mocks;

use Sanchescom\WiFi\Test\NetworksCommandAbstract;

/**
 * Class NetworksColonCommand.
 */
class NetworksColonCommand extends NetworksCommandAbstract
{
    /** @var string */
    protected static $mock = __DIR__.'/NetworksColon.txt';
}
