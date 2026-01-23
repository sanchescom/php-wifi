<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

use Sanchescom\WiFi\Contracts\CommandInterface;
use Sanchescom\WiFi\Exceptions\CommandException;

/**
 * Class CommandExecutor.
 */
class Command implements CommandInterface
{
    protected string $lastCommand = '';

    public function execute(string $command): string|int
    {
        $command .= ' 2>&1';

        exec($command, $output, $code);

        $result = count($output) === 0
            ? $code
            : implode(PHP_EOL, $output);

        $this->lastCommand = is_int($result) ? (string)$result : $result;

        if ($code !== 0) {
            throw new CommandException($command, (string)$result, $code);
        }

        return $result;
    }

    public function getLastCommand(): string
    {
        return $this->lastCommand;
    }
}
