<?php

declare(strict_types=1);

namespace Sanchescom\WiFi;

use Sanchescom\WiFi\Exceptions\UnknownSystemException;
use Sanchescom\WiFi\System\AbstractNetworksCollection;
use Sanchescom\WiFi\System\Command;
use Sanchescom\WiFi\System\Linux\NetworksCollection as LinuxNetworks;
use Sanchescom\WiFi\System\Mac\NetworksCollection as MacNetworks;
use Sanchescom\WiFi\System\Windows\NetworksCollection as WindowsNetworks;
use Tightenco\Collect\Support\Collection;

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
     * @return Collection
     */
    public static function scan(): Collection
    {
        return (new static())->getSystemNetwork()->scan();
    }

    /**
     * Getting instance on network collections depended on operation system.
     *
     * @throws UnknownSystemException
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
            throw new UnknownSystemException();
        }
    }

    /**
     * @return bool
     */
    protected function isWindows(): bool
    {
        return os_prefix() == self::OS_WIN;
    }

    /**
     * @return bool
     */
    protected function isMac(): bool
    {
        return os_prefix() == self::OS_OSX;
    }

    /**
     * @return bool
     */
    protected function isLinux(): bool
    {
        return os_prefix() == self::OS_LINUX;
    }

    /**
     * @return WindowsNetworks
     */
    protected function windowsNetwork(): AbstractNetworksCollection
    {
        return new WindowsNetworks($this->getCommandExecutor());
    }

    /**
     * @return MacNetworks
     */
    protected function macNetwork(): AbstractNetworksCollection
    {
        return new MacNetworks($this->getCommandExecutor());
    }

    /**
     * @return LinuxNetworks
     */
    protected function linuxNetwork(): AbstractNetworksCollection
    {
        return new LinuxNetworks($this->getCommandExecutor());
    }

    /**
     * @return Command
     */
    protected function getCommandExecutor()
    {
        return new Command();
    }
}
