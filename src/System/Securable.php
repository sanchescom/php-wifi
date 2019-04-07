<?php

namespace Sanchescom\WiFi\System;

trait Securable
{
    public function isWpa2()
    {
        return strpos($this->security, 'WPA2') !== false;
    }

    public function isWpa()
    {
        return strpos($this->security, 'WPA') !== false;
    }

    public function isWep()
    {
        return strpos($this->security, 'WEP') !== false;
    }

    public function isUnknown()
    {
        return (!$this->isWep() && !$this->isWpa() && !$this->isWpa2());
    }
}