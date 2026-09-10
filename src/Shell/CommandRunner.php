<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Shell;

interface CommandRunner
{
    public function run(Command $command): CommandResult;
}
