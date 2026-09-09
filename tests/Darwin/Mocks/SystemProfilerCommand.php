<?php

namespace Sanchescom\WiFi\Test\Darwin\Mocks;

use Sanchescom\WiFi\Test\NetworksCommandAbstract;

class SystemProfilerCommand extends NetworksCommandAbstract
{
    /** @var string */
    protected static $mock = __DIR__.'/../../Fixtures/darwin/SystemProfiler.txt';

    /**
     * The real Darwin command runs system_profiler twice with a separator
     * between the runs; the fixture holds a single run, so emit it twice.
     */
    public function execute(string $command)
    {
        $this->lastCommand = $command;
        $output = file_get_contents(static::$mock);

        return $output."\n--separator--\n".$output;
    }
}
