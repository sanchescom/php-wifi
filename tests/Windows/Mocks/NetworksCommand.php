<?php

namespace Sanchescom\WiFi\Test\Windows\Mocks;

use Sanchescom\WiFi\Contracts\CommandInterface;

/**
 * Class Command.
 */
class NetworksCommand implements CommandInterface
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