<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Exceptions;

use RuntimeException;

/**
 * Class CommandException.
 */
class CommandException extends RuntimeException
{
    /** @var string */
    protected $command;

    /** @var string */
    protected $output;

    /** @var int */
    protected $returnCode;

    /**
     * CommandException constructor.
     *
     * @param string $command
     * @param string $output
     * @param int    $returnCode
     */
    public function __construct(string $command, string $output, int $returnCode)
    {
        if ($this->returnCode == 127) {
            $message = 'Command not found: "'.$command.'"';
        } else {
            $message = 'Command "'.$command.'" exited with code '.$returnCode.': '.$output;
        }

        parent::__construct($message);
    }
}
