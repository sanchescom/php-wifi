<?php

namespace Sanchescom\WiFi\System;

use InvalidArgumentException;
use Sanchescom\WiFi\Exceptions\CommandException;

/**
 * Class CommandExecutor.
 */
class CommandExecutor
{
    public function execute($command, $mergeStdErr = true)
    {
        if (empty($command)) {
            throw new InvalidArgumentException('Command line is empty');
        }

        if ($mergeStdErr) {
            // Redirect stderr to stdout to include it in $output
            $command .= ' 2>&1';
        }

        exec($command, $output, $code);

        if (count($output) === 0) {
            $output = $code;
        } else {
            $output = implode(PHP_EOL, $output);
        }

        if ($code !== 0) {
            throw new CommandException($command, $output, $code);
        }

        return $output;
    }
}
