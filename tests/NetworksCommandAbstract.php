<?php

namespace Sanchescom\WiFi\Test;

use Sanchescom\WiFi\Contracts\CommandInterface;

/**
 * Class Command.
 */
abstract class NetworksCommandAbstract implements CommandInterface
{
    /**
     * @param string $command
     *
     * @return string
     */
    public function execute(string $command)
    {
        return file_get_contents(__DIR__ . '/Networks.txt');
    }
}