<?php

namespace Sanchescom\WiFi\Test;

use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Class BaseTestCase.
 */
abstract class BaseTestCase extends TestCase
{
    /**
     * Tear down test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
