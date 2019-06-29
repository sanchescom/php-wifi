<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

/**
 * Class AbstractNetworks.
 */
abstract class AbstractNetworks
{
    /**
     * @var \Sanchescom\WiFi\System\AbstractNetwork[]
     */
    protected $networks;

    /** @var \Sanchescom\WiFi\System\Command */
    protected $command;

    /**
     * @param string $output
     *
     * @return array
     */
    abstract protected function extractingNetworks(string $output): array;

    /**
     * @return \Sanchescom\WiFi\System\AbstractNetwork
     */
    abstract protected function getNetwork(): AbstractNetwork;

    /**
     * @return string
     */
    abstract protected function getCommand(): string;

    /**
     * AbstractNetworks constructor.
     *
     * @param \Sanchescom\WiFi\System\Command $command
     */
    public function __construct(Command $command)
    {
        $this->command = $command;
    }

    /**
     * @throws \Exception
     *
     * @return \Sanchescom\WiFi\System\Collection
     */
    public function scan(): Collection
    {
        $output = $this->command->execute($this->getCommand());

        $this->setNetworks(
            $this->extractingNetworks($output)
        );

        return new Collection($this->networks);
    }

    /**
     * @param array $networks
     *
     * @return void
     */
    protected function setNetworks(array $networks): void
    {
        $this->networks = array_map(function (array $network) {
            return $this->getNetwork()->createFromArray($network);
        }, $networks);
    }

    /**
     * @param string $networksString
     *
     * @return array
     */
    protected function explodeAvailableNetworks(string $networksString): array
    {
        return explode("\n", trim($networksString));
    }
}
