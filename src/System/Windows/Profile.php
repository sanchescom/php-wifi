<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Windows;

/**
 * Class Service.
 */
class Profile
{
    /**
     * @var string
     */
    protected $ssid;

    /**
     * @var string
     */
    protected $securityType;

    /**
     * Service constructor.
     *
     * @param string $ssid
     * @param string $securityType
     */
    public function __construct(string $ssid, string $securityType)
    {
        $this->ssid = $ssid;
        $this->securityType = $securityType;
    }

    /**
     * @param string $password
     *
     * @return string
     */
    public function create(string $password): string
    {
        file_put_contents($this->getTmpFileName(), $this->renderTemplate($password));

        return $this->getTmpFileName();
    }

    /**
     * Delete tmp profile file created for chosen network.
     */
    public function delete(): bool
    {
        $tmpProfile = $this->getTmpFileName();

        if (file_exists($tmpProfile)) {
            return unlink($tmpProfile);
        }

        return false;
    }

    /**
     * @return string
     */
    protected function getTmpFileName(): string
    {
        return __DIR__.'/../../../tmp/'.$this->ssid.'.xml';
    }

    /**
     * @return string
     */
    protected function getTemplateFileName(): string
    {
        return __DIR__.'/../../../templates/'.$this->securityType.'.xml';
    }

    /**
     * @param string $password
     *
     * @return string
     */
    protected function renderTemplate(string $password): string
    {
        $content = file_get_contents($this->getTemplateFileName()) ?: '';

        return str_replace(
            [
                '{ssid}',
                '{hex}',
                '{key}',
            ],
            [
                $this->ssid,
                to_hex($this->ssid),
                $password,
            ],
            $content
        );
    }
}
