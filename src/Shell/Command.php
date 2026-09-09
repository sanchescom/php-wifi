<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Shell;

/**
 * One process invocation: a program, its arguments and an environment.
 * Rendering to a shell string — and therefore all escaping — happens here
 * and nowhere else.
 */
final readonly class Command
{
    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     * @param list<int> $secretIndexes indexes into $arguments that must never be displayed
     */
    public function __construct(
        public string $program,
        public array $arguments = [],
        public array $env = [],
        public array $secretIndexes = [],
    ) {
    }

    public function toShell(Os $os): string
    {
        return $this->render($os, false);
    }

    /** The shell form with secret arguments replaced by ***. */
    public function toDisplay(Os $os): string
    {
        return $this->render($os, true);
    }

    /** Program and arguments joined by spaces, unescaped, secrets masked. For logs and test matching. */
    public function describe(): string
    {
        return implode(' ', [$this->program, ...$this->maskedArguments()]);
    }

    private function render(Os $os, bool $masked): string
    {
        $tokens = [$this->program, ...($masked ? $this->maskedArguments() : $this->arguments)];
        $quoted = array_map($os->isPosix() ? escapeshellarg(...) : self::quoteForCmd(...), $tokens);
        $rendered = implode(' ', $quoted);

        if ($os->isPosix() && $this->env !== []) {
            $prefix = [];
            foreach ($this->env as $name => $value) {
                $prefix[] = $name . '=' . escapeshellarg($value);
            }
            $rendered = implode(' ', $prefix) . ' ' . $rendered;
        }

        return $rendered;
    }

    /** @return list<string> */
    private function maskedArguments(): array
    {
        $masked = $this->arguments;
        foreach ($this->secretIndexes as $index) {
            if (array_key_exists($index, $masked)) {
                $masked[$index] = '***';
            }
        }

        return $masked;
    }

    /**
     * cmd.exe has no single quotes and expands %VAR% and !VAR! even inside
     * double quotes; the three characters that can break out of an argument
     * are replaced with a space, and a trailing odd run of backslashes is
     * doubled so CommandLineToArgvW does not read \" as a literal quote.
     */
    private static function quoteForCmd(string $value): string
    {
        $value = str_replace(['"', '%', '!'], ' ', $value);

        if (preg_match('/(\\\\+)$/', $value, $m) === 1 && strlen($m[1]) % 2 === 1) {
            $value .= '\\';
        }

        return '"' . $value . '"';
    }
}
