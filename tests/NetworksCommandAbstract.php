<?php

namespace Sanchescom\WiFi\Test;

use Sanchescom\WiFi\Contracts\CommandInterface;

/**
 * Class Command.
 */
abstract class NetworksCommandAbstract implements CommandInterface
{
    /** @var string */
    protected static $mock = '';

    /**
     * @param string $command
     *
     * @return string
     */
    public function execute(string $command)
    {
        return file_get_contents(static::$mock);
    }
}
