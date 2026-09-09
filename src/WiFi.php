<?php

declare(strict_types=1);

namespace Sanchescom\WiFi;

use Sanchescom\WiFi\Contracts\CommandInterface;
use Sanchescom\WiFi\Exceptions\UnknownSystemException;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\AbstractNetworks;
use Sanchescom\WiFi\System\Collection;
use Sanchescom\WiFi\System\Command;
use Sanchescom\WiFi\System\Darwin\Networks as DarwinNetworks;
use Sanchescom\WiFi\System\Linux\Networks as LinuxNetworks;
use Sanchescom\WiFi\System\Windows\Networks as WindowsNetworks;

/**
 * Class WiFi.
 *
 * @phpstan-consistent-constructor
 */
class WiFi
{
    public const OS_LINUX = 'Linux';
    public const OS_DARWIN = 'Darwin';
    public const OS_WINDOWS = 'Windows';

    protected static string $commandClass = Command::class;
    protected static string $phpOperationSystem = PHP_OS_FAMILY;

    /**
     * @var array<string, class-string<AbstractNetworks>>
     */
    protected static array $systems = [
        self::OS_LINUX   => LinuxNetworks::class,
        self::OS_DARWIN  => DarwinNetworks::class,
        self::OS_WINDOWS => WindowsNetworks::class,
    ];

    /**
     * Scan for available WiFi networks.
     */
    public static function scan(): Collection
    {
        return (new static())->getSystemInstance()->scan();
    }

    /**
     * Get all connected networks.
     *
     * @return AbstractNetwork[]
     */
    public static function getConnected(): array
    {
        return static::scan()->getConnected();
    }

    /**
     * Find strongest available network.
     *
     * @throws \Sanchescom\WiFi\Exceptions\NetworkNotFoundException
     */
    public static function getStrongestNetwork(): AbstractNetwork
    {
        return static::scan()->getStrongest();
    }

    /**
     * Get networks by security type.
     */
    public static function getNetworksBySecurity(string $securityType): Collection
    {
        return static::scan()->getBySecurity($securityType);
    }

    /**
     * Get networks on 2.4GHz band.
     */
    public static function get24GhzNetworks(): Collection
    {
        return static::scan()->get24GhzNetworks();
    }

    /**
     * Get networks on 5GHz band.
     */
    public static function get5GhzNetworks(): Collection
    {
        return static::scan()->get5GhzNetworks();
    }

    /**
     * Get networks on the 6 GHz band.
     */
    public static function get6GhzNetworks(): Collection
    {
        return static::scan()->get6GhzNetworks();
    }

    public static function setCommandClass(string $commandClass): void
    {
        self::$commandClass = $commandClass;
    }

    public static function setPhpOperationSystem(string $phpOperationSystem): void
    {
        self::$phpOperationSystem = $phpOperationSystem;
    }

    /**
     * Getting instance on network collections depended on operation system.
     *
     * @throws \Sanchescom\WiFi\Exceptions\UnknownSystemException
     *
     * @return \Sanchescom\WiFi\System\AbstractNetworks
     */
    protected function getSystemInstance(): AbstractNetworks
    {
        if (!array_key_exists(static::$phpOperationSystem, static::$systems)) {
            throw new UnknownSystemException();
        }

        return new static::$systems[static::$phpOperationSystem]($this->getCommandInstance());
    }

    /**
     * @return \Sanchescom\WiFi\Contracts\CommandInterface
     */
    protected function getCommandInstance(): CommandInterface
    {
        return new static::$commandClass();
    }
}
