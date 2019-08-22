<?php

declare(strict_types=1);

namespace Sanchescom\WiFi;

use Sanchescom\WiFi\Contracts\CommandInterface;
use Sanchescom\WiFi\Exceptions\UnknownSystemException;
use Sanchescom\WiFi\System\AbstractNetworks;
use Sanchescom\WiFi\System\Collection;
use Sanchescom\WiFi\System\Command;
use Sanchescom\WiFi\System\Darwin\Networks as DarwinNetworks;
use Sanchescom\WiFi\System\Linux\Networks as LinuxNetworks;
use Sanchescom\WiFi\System\Windows\Networks as WindowsNetworks;

/**
 * Class WiFi.
 */
class WiFi
{
    /** @var string */
    const OS_LINUX = 'Linux';

    /** @var string */
    const OS_DARWIN = 'Darwin';

    /** @var string */
    const OS_WINDOWS = 'Windows';

    /** @var string */
    protected static $commandClass = Command::class;

    /** @var string */
    protected static $phpOperationSystem = PHP_OS_FAMILY;

    /** @var array */
    protected static $systems = [
        self::OS_LINUX   => LinuxNetworks::class,
        self::OS_DARWIN  => DarwinNetworks::class,
        self::OS_WINDOWS => WindowsNetworks::class,
    ];

    /**
     * @return \Sanchescom\WiFi\System\Collection
     */
    public static function scan(): Collection
    {
        return (new static())->getSystemInstance()->scan();
    }

    /**
     * @param string $commandClass
     */
    public static function setCommandClass(string $commandClass): void
    {
        self::$commandClass = $commandClass;
    }

    /**
     * @param string $phpOperationSystem
     */
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
