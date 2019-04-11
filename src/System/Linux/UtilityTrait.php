<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Linux;

trait UtilityTrait
{
    /** @var string */
    private static $utility = 'nmcli';

    /**
     * @return string
     */
    public function getUtility()
    {
        return implode(' ', array_merge($this->getEnvs(), [
            static::$utility,
        ]));
    }

    /**
     * @return array
     */
    private function getEnvs()
    {
        return [
            'LANG=C',
        ];
    }
}
