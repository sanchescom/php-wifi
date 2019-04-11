<?php

declare(strict_types=1);

namespace Sanchescom\WiFi;

use Sanchescom\WiFi\Exceptions\UnknownSystem;
use Sanchescom\WiFi\System\AbstractNetworksCollection;
use Sanchescom\WiFi\System\Linux\NetworksCollection as LinuxNetworks;
use Sanchescom\WiFi\System\Mac\NetworksCollection as MacNetworks;
use Sanchescom\WiFi\System\Windows\NetworksCollection as WindowsNetworks;

/**
 * Class WiFi.
 */
class WiFi
{
    /**
     * @var string
     */
    const OS_WIN = 'WIN';

    /**
     * @var string
     */
    const OS_LINUX = 'LINUX';

    /**
     * @var string
     */
    const OS_OSX = 'DAR';

    /**
     * @return AbstractNetworksCollection
     */
    public static function scan(): AbstractNetworksCollection
    {
        return (new static())->getSystemNetwork()->scan();
    }

    /**
     * Getting instance on network collections depended on operation system.
     *
     * @throws UnknownSystem
     *
     * @return AbstractNetworksCollection
     */
    protected function getSystemNetwork(): AbstractNetworksCollection
    {
        if ($this->isWindows()) {
            return $this->windowsNetwork();
        } elseif ($this->isMac()) {
            return $this->macNetwork();
        } elseif ($this->isLinux()) {
            return $this->linuxNetwork();
        } else {
            throw new UnknownSystem("Operation system doesn't support");
        }
    }

    /**
     * @return bool
     */
    protected function isWindows(): bool
    {
        return (bool) stristr(PHP_OS, self::OS_WIN);
    }

    /**
     * @return bool
     */
    protected function isMac(): bool
    {
        return (bool) stristr(PHP_OS, self::OS_OSX);
    }

    /**
     * @return bool
     */
    protected function isLinux(): bool
    {
        return (bool) stristr(PHP_OS, self::OS_LINUX);
    }

    /**
     * @return WindowsNetworks
     */
    protected function windowsNetwork(): AbstractNetworksCollection
    {
        return new WindowsNetworks();
    }

    /**
     * @return MacNetworks
     */
    protected function macNetwork(): AbstractNetworksCollection
    {
        return new MacNetworks();
    }

    /**
     * @return LinuxNetworks
     */
    protected function linuxNetwork(): AbstractNetworksCollection
    {
        return new LinuxNetworks();
    }
}
