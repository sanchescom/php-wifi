<?php

namespace Sanchescom\WiFi\Test;

use Sanchescom\WiFi\Exceptions\CommandException;
use Sanchescom\WiFi\System\Command;

class CommandsTest extends BaseTestCase
{
    /** @var string */
    const DIR_NAME = 'test';

    /** {@inheritdoc} */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @test
     */
    public function it_should_execute_commands()
    {
        $command = new Command();

        if (file_exists(self::DIR_NAME)) {
            $command->execute('rmdir '.self::DIR_NAME);
        }

        $result = $command->execute('mkdir '.self::DIR_NAME);

        $this->assertEquals($result, 0);
        $this->assertDirectoryExists(self::DIR_NAME);

        $result = $command->execute('rmdir '.self::DIR_NAME);

        $this->assertEquals($result, 0);

        if (file_exists(self::DIR_NAME)) {
            $command->execute('rmdir '.self::DIR_NAME);
        }

        $this->expectException(CommandException::class);
        $command->execute('qwe');
    }
}
