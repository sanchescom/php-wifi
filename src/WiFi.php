<?php

declare(strict_types=1);

namespace Sanchescom\WiFi;

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
     * @throws \Sanchescom\WiFi\Exceptions\UnknownSystemException
     *
     * @return \Sanchescom\WiFi\System\AbstractNetworks
     */
    protected function getSystemNetwork(): AbstractNetworks
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
     * @return \Sanchescom\WiFi\System\Windows\Networks
     */
    protected function windowsNetwork(): AbstractNetworks
    {
        return new WindowsNetworks($this->getCommandExecutor());
    }

    /**
     * @return \Sanchescom\WiFi\System\Mac\Networks
     */
    protected function macNetwork(): AbstractNetworks
    {
        return new MacNetworks($this->getCommandExecutor());
    }

    /**
     * @return \Sanchescom\WiFi\System\Linux\Networks
     */
    protected function linuxNetwork(): AbstractNetworks
    {
        return new LinuxNetworks($this->getCommandExecutor());
    }

    /**
     * @return \Sanchescom\WiFi\System\Command
     */
    protected function getCommandExecutor()
    {
        return new Command();
    }
}
