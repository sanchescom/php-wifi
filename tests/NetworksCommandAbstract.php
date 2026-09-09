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

    /** @var array<int, string> */
    public array $commands = [];

    /** @var string */
    protected static $mock = '';

    /**
     * Command substring => fixture file, checked in order; first match wins.
     *
     * @var array<string, string>
     */
    protected static array $mocks = [];

    public function execute(string $command)
    {
        $this->lastCommand = $command;
        $this->commands[] = $command;

        foreach (static::$mocks as $needle => $file) {
            if (str_contains($command, $needle)) {
                return file_get_contents($file);
            }
        }

        return file_get_contents(static::$mock);
    }

    public function getLastCommand()
    {
        return $this->lastCommand;
    }
}
