<?php

namespace Sanchescom\WiFi\System;

use Sanchescom\WiFi\Exceptions\CommandException;

/**
 * Class CommandExecutor.
 */
class Command
{
    /**
     * @param string $command
     *
     * @return string
     */
    public function execute(string $command)
    {
        $command .= ' 2>&1';

        exec($command, $output, $code);

        $output = count($output) === 0
            ? $code
            : implode(PHP_EOL, $output);

        if ($code !== 0) {
            throw new CommandException($command, $output, $code);
        }

        return $output;
    }
}
