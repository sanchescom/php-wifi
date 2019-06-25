<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

use Closure;
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

    /** @var CommandExecutor */
    protected $commandExecutor;

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
     * @param CommandExecutor $commandExecutor
     */
    public function __construct(CommandExecutor $commandExecutor)
    {
        $this->commandExecutor = $commandExecutor;
    }

    /**
     * @throws Exception
     *
     * @return Collection
     */
    public function scan(): Collection
    {
        $output = $this->commandExecutor->execute($this->getCommand());

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
        $this->networks = array_map(function ($network) {
            return call_user_func_array([
                $this->getNetwork(),
                'createFromArray'
            ], [
                $network,
                $this->commandExecutor
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
