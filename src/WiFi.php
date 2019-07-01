<?php

declare(strict_types=1);

namespace Sanchescom\WiFi;

use Sanchescom\WiFi\Contracts\CommandInterface;
use Sanchescom\WiFi\Exceptions\UnknownSystemException;
use Sanchescom\WiFi\System\AbstractNetworks;
use Sanchescom\WiFi\System\Collection;
use Sanchescom\WiFi\System\Command;
use Sanchescom\WiFi\System\Linux\Networks as LinuxNetworks;
use Sanchescom\WiFi\System\Mac\Networks as MacNetworks;
use Sanchescom\WiFi\System\Windows\Networks as WindowsNetworks;

/**
 * Class WiFi.
 */
class WiFi
{
    /** @var string */
    const OS_LINUX = 'LINUX';

    /** @var string */
    const OS_OSX = 'DAR';

    /** @var string */
    const OS_WIN = 'WIN';

    /** @var string */
    protected static $commandClass = Command::class;

    /** @var string */
    protected static $phpOperationSystem = PHP_OS;

    /** @var array */
    protected static $systems = [
        self::OS_LINUX => LinuxNetworks::class,
        self::OS_OSX   => MacNetworks::class,
        self::OS_WIN   => WindowsNetworks::class,
    ];

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
     * @return \Sanchescom\WiFi\System\Collection
     */
    public static function scan(): Collection
    {
        return (new static())->getSystemInstance()->scan();
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
        $operationSystem = $this->getOperationSystem();

        if (!array_key_exists($operationSystem, static::$systems)) {
            throw new UnknownSystemException();
        }

        return new static::$systems[$operationSystem]($this->getCommandInstance());
    }

    /**
     * @return \Sanchescom\WiFi\Contracts\CommandInterface
     */
    protected function getCommandInstance(): CommandInterface
    {
        return new static::$commandClass();
    }

    /**
     * Getting prefix from operation system name.
     *
     * @return string
     */
    protected function getOperationSystem()
    {
        return strtoupper(substr(self::$phpOperationSystem, 0, 3));
    }
}
