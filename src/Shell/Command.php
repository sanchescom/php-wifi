<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Shell;

use Sanchescom\WiFi\Exception\InvalidArgument;

/**
 * One process invocation: a program, its arguments and an environment.
 * Rendering to a shell string — and therefore all escaping — happens here
 * and nowhere else.
 */
final readonly class Command
{
    /**
     * @param list<string> $arguments
     * @param array<string, string> $env never pass secrets through env — display
     *        forms (toDisplay(), describe()) print env values verbatim, unmasked
     * @param list<int> $secretIndexes indexes into $arguments that must never be displayed
     *
     * @throws InvalidArgument when a secret index does not exist in $arguments, an
     *         env name is not a valid POSIX identifier, or the program, an
     *         argument or an env value contains a NUL byte
     */
    public function __construct(
        public string $program,
        public array $arguments = [],
        public array $env = [],
        public array $secretIndexes = [],
    ) {
        if (str_contains($program, "\0")) {
            throw new InvalidArgument('Command program must not contain a NUL byte.');
        }

        foreach ($arguments as $argument) {
            if (str_contains($argument, "\0")) {
                throw new InvalidArgument('Command arguments must not contain a NUL byte.');
            }
        }

        foreach ($env as $name => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgument(sprintf('"%s" is not a valid environment variable name.', $name));
            }

            if (str_contains($value, "\0")) {
                throw new InvalidArgument(sprintf('Environment variable "%s" must not contain a NUL byte.', $name));
            }
        }

        foreach ($secretIndexes as $index) {
            if (!array_key_exists($index, $arguments)) {
                throw new InvalidArgument(sprintf('Secret index %d does not exist in the argument list.', $index));
            }
        }
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
     * double quotes, and a bare newline or carriage return inside the quotes
     * can start a second command; the five characters that can break out of
     * an argument (", %, !, \n, \r) are replaced with a space, and a
     * trailing odd run of backslashes is padded to an even length — NOT
     * doubled — so CommandLineToArgvW does not read \" as a literal quote.
     * Padding to even is lossy for runs of two or more trailing backslashes
     * (an odd run of N becomes N+1, not the mathematically doubled 2N); this
     * is spec-mandated, matching the 2.x behaviour.
     */
    private static function quoteForCmd(string $value): string
    {
        $value = str_replace(['"', '%', '!', "\n", "\r"], ' ', $value);

        if (preg_match('/(\\\\+)$/', $value, $m) === 1 && strlen($m[1]) % 2 === 1) {
            $value .= '\\';
        }

        return '"' . $value . '"';
    }
}
