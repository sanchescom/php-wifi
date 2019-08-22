<?php

namespace Sanchescom\WiFi\Contracts;

/**
 * Interface CommandInterface.
 */
interface CommandInterface
{
    /**
     * @param string $command
     *
     * @return string
     */
    public function execute(string $command);

    /**
     * @return string
     */
    public function getLastCommand();
}
