<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

use Exception;
use Tightenco\Collect\Support\Collection;

/**
 * Class AbstractNetworksCollection.
 */
abstract class AbstractNetworksCollection
{
    /**
     * @var AbstractNetwork[]
     */
    protected $networks;

    /** @var Command */
    protected $command;

    /**
     * @param string $output
     *
     * @return array
     */
    abstract protected function extractingNetworks(string $output): array;

    /**
     * @return string
     */
    abstract protected function getNetwork():? string;

    /**
     * @return string
     */
    abstract protected function getCommand(): string;

    /**
     * AbstractNetworksCollection constructor.
     *
     * @param Command $command
     */
    public function __construct(Command $command)
    {
        $this->command = $command;
    }

    /**
     * @throws Exception
     *
     * @return Collection
     */
    public function scan(): Collection
    {
        $output = $this->command->execute($this->getCommand());

        $this->setNetworks(
            $this->extractingNetworks($output)
        );

        return collect($this->networks);
    }

    /**
     * @param array $networks
     *
     * @return void
     */
    protected function setNetworks(array $networks): void
    {
        $this->networks = array_map(function (array $network) {
            return call_user_func_array([
                $this->getNetwork(),
                'createFromArray',
            ], [
                $network,
                $this->command,
            ]);
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
