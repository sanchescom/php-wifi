<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend\Linux;

use RuntimeException;

/**
 * Where php-wifi keeps the files that outlive one process: the `hostapd` and
 * `dnsmasq` pid files, the `hostapd` config and the watchdog's state file.
 *
 * They used to sit at fixed names in the system temp directory, which is
 * world-writable: root wrote them, so any local user could plant a symlink
 * under one of those names and have root overwrite the file it pointed at.
 * They now live in a directory only its owner can write to — `/run/php-wifi`
 * for root, the only user who can raise a hotspot through `hostapd` — and
 * {@see self::ensure()} refuses a directory that is a symlink, belongs to
 * someone else or is writable by group or others.
 *
 * The path is fixed rather than taken from systemd's `RUNTIME_DIRECTORY`,
 * because a second process — `wifi hotspot status` from a shell, the watchdog
 * under its own unit — has to find the same files. `WIFI_RUNTIME_DIR`
 * overrides it for every process that should share a different one.
 */
final class RuntimeDirectory
{
    private const SYSTEM_PATH = '/run/php-wifi';

    public function __construct(private readonly ?string $path = null)
    {
    }

    /** The directory's path. Creates nothing. */
    public function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        $override = getenv('WIFI_RUNTIME_DIR');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }

        // Only root can write to /run; anyone else gets a directory of their own.
        if (is_dir(self::SYSTEM_PATH) ? is_writable(self::SYSTEM_PATH) : is_writable(dirname(self::SYSTEM_PATH))) {
            return self::SYSTEM_PATH;
        }

        return sys_get_temp_dir() . '/php-wifi-' . (self::userId() ?? 'user');
    }

    /**
     * Creates the directory (0700) when it is missing and returns its path.
     *
     * @throws RuntimeException when the directory cannot be created, or is not safe to write into
     */
    public function ensure(): string
    {
        $path = $this->path();

        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }

        if (!is_dir($path) && !is_link($path)) {
            throw new RuntimeException(sprintf('The runtime directory "%s" cannot be created.', $path));
        }

        if (!$this->isSafe()) {
            throw new RuntimeException(sprintf(
                'The runtime directory "%s" is not safe to use: it must belong to the current user and be writable'
                . ' by nobody else.',
                $path,
            ));
        }

        return $path;
    }

    /**
     * Whether the directory exists and can be trusted: a real directory, not
     * a symlink, owned by the current user and writable by nobody else.
     * Reading a pid or a timestamp out of anything less would let whoever
     * prepared it choose which process gets signalled.
     */
    public function isSafe(): bool
    {
        $path = $this->path();

        clearstatcache(true, $path);

        return !is_link($path)
            && is_dir($path)
            && fileowner($path) === self::userId()
            && (fileperms($path) & 0022) === 0;
    }

    /**
     * The effective user id, or null when it cannot be learned — which
     * {@see self::isSafe()} then treats as "not the owner".
     */
    private static function userId(): ?int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        // Without ext-posix: on Linux the kernel reports the process's own user as the owner of /proc/self.
        $owner = @fileowner('/proc/self');

        return $owner === false ? null : $owner;
    }
}
