<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Shell;

final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
