<?php

namespace Sanchescom\WiFi\System;

use InvalidArgumentException;
use Sanchescom\WiFi\Exceptions\CommandException;

/**
 * Class CommandExecutor.
 */
class Command
{
    /**
     * @param string $command
     * @param bool $mergeStdErr
     *
     * @return string
     */
    public function execute(string $command, bool $mergeStdErr = true)
    {
        if (empty($command)) {
            throw new InvalidArgumentException('Command line is empty');
        }

        if ($mergeStdErr) {
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
