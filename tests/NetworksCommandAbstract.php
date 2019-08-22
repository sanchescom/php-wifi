<?php

namespace Sanchescom\WiFi\Test;

use Sanchescom\WiFi\Contracts\CommandInterface;

/**
 * Class Command.
 */
abstract class NetworksCommandAbstract implements CommandInterface
{
    /** @var string */
    public $lastCommand;

    /** @var string */
    protected static $mock = '';

    /**
     * @param string $command
     *
     * @return string
     */
    public function execute(string $command)
    {
        $this->lastCommand = $command;

        return file_get_contents(static::$mock);
    }

    public function getLastCommand()
    {
        return $this->lastCommand;
    }
}
