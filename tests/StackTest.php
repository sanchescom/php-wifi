<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\System\Windows\Network;

class StackTest extends TestCase
{
    public function testDBmToQuality()
    {
    }

    /**
     * @throws \ReflectionException
     */
    public function testWindowsNetwork()
    {
        $windowsNetwork = new Network();

        $this->assertIsFloat($this->invokeMethod($windowsNetwork, 'dBmToQuality'));
        //$this->assertEquals(1, $windowsNetwork->dBmToQuality());
    }

    /**
     * Call protected/private method of a class.
     *
     * @param object &$object Instantiated object that we will run method on.
     * @param string $methodName Method name to call
     * @param array $parameters Array of parameters to pass into method.
     *
     * @return mixed Method return.
     * @throws \ReflectionException
     */
    public function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}