<?php

declare(strict_types=1);

namespace Sanchescom\WiFi;

use Exception;
use Sanchescom\WiFi\Exceptions\UnknownSystem;
use Sanchescom\WiFi\System\AbstractNetworksCollection;
use Sanchescom\WiFi\System\Mac\NetworksCollection as MacNetworks;
use Sanchescom\WiFi\System\Windows\NetworksCollection as WindowsNetworks;
use Sanchescom\WiFi\System\Linux\NetworksCollection as LinuxNetworks;

/**
 * Class WiFi.
 */
class WiFi
{
    /**
     * @var int
     */
    const OS_UNKNOWN = 1;

    /**
     * @var int
     */
    const OS_WIN = 2;

    /**
     * @var int
     */
    const OS_LINUX = 3;

    /**
     * @var int
     */
    const OS_OSX = 4;

    /**
     * @var AbstractNetworksCollection
     */
    protected static $systemNetworks;

    /**
     * @return AbstractNetworksCollection
     * @throws Exception
     */
    public static function scan(): AbstractNetworksCollection
    {
        self::init();
        return self::$systemNetworks->scan();
    }

    /**
     * @return int
     */
    protected static function getOS(): int
    {
        switch (true) {
            case stristr(PHP_OS, 'DAR'):
                return self::OS_OSX;
            case stristr(PHP_OS, 'WIN'):
                return self::OS_WIN;
            case stristr(PHP_OS, 'LINUX'):
                return self::OS_LINUX;
            default:
                return self::OS_UNKNOWN;
        }
    }

    /**
     * @throws UnknownSystem
     */
    private static function init(): void
    {
        if (self::getOS() == self::OS_WIN) {
            self::$systemNetworks = new WindowsNetworks();
        } elseif (self::getOS() == self::OS_OSX) {
            self::$systemNetworks = new MacNetworks();
        } elseif (self::getOS() == self::OS_LINUX) {
            self::$systemNetworks = new LinuxNetworks();
        } else {
            throw new UnknownSystem("Operation system doesn't support");
        }
    }
}
