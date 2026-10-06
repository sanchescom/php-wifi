<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Provision;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Provision\Supervisor;
use Sanchescom\WiFi\Provision\SupervisorState;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\HotspotConfig;

final class SupervisorTest extends ProvisionTestCase
{
    private function supervisor(FakeCommandRunner $runner, int $maxFailedRestarts = 5): Supervisor
    {
        return new Supervisor(
            $this->wifi($runner),
            new HotspotConfig('femus-setup', 'password1', device: new Device('wlan0'), captivePortal: true),
            $this->state(),
            $maxFailedRestarts,
        );
    }

    private static function ups(FakeCommandRunner $runner): int
    {
        return count(array_filter(self::commands($runner), static fn ($c) => str_contains($c, 'connection up uuid')));
    }

    /** With one radio the scan has to happen first; once the hotspot is up there is nothing to scan with. */
    #[Test]
    public function start_scans_caches_the_list_and_only_then_raises_the_hotspot(): void
    {
        $runner = $this->runner();
        $this->state()->endAttempt('Old', true, null, null);

        $this->supervisor($runner)->start();

        $commands = self::commands($runner);
        $this->assertStringContainsString('device wifi list', $commands[0]);
        $this->assertStringContainsString('connection up uuid', (string) end($commands));
        $this->assertSame('AlphaNet-foiEmE', $this->state()->cachedNetworks()[0]['ssid'] ?? null);
        $this->assertNull($this->state()->joinedSsid(), 'a previous run must not count as this one');
    }

    #[Test]
    public function a_hotspot_that_is_up_is_left_alone(): void
    {
        $runner = $this->runner(['connection show --active' => "Hotspot\n"]);

        $this->assertSame(SupervisorState::Serving, $this->supervisor($runner)->tick());
        $this->assertSame(0, self::ups($runner));
    }

    /** A failed join leaves the radio idle with no access point; the phone needs something to come back to. */
    #[Test]
    public function a_hotspot_that_is_down_with_no_join_running_is_raised_again(): void
    {
        $runner = $this->runner();

        $this->assertSame(SupervisorState::Restarted, $this->supervisor($runner)->tick());
        $this->assertSame(1, self::ups($runner));
    }

    /** The defect the 3.2.5 live run found in the shell version of this loop. */
    #[Test]
    public function a_hotspot_that_is_down_because_a_join_is_running_is_not_raised(): void
    {
        $runner = $this->runner();
        $this->state()->beginAttempt();

        $this->assertSame(SupervisorState::Joining, $this->supervisor($runner)->tick());
        $this->assertSame([], $runner->commands);
    }

    #[Test]
    public function a_join_that_worked_ends_the_run_without_touching_the_radio(): void
    {
        $runner = $this->runner();
        $this->state()->endAttempt('Home', true, null, null);

        $this->assertSame(SupervisorState::Done, $this->supervisor($runner)->tick());
        $this->assertSame([], $runner->commands);
    }

    /** A radio that will not come up must end the run, not spin for the whole timeout. */
    #[Test]
    public function restarts_that_keep_failing_end_in_the_last_failure(): void
    {
        $runner = $this->runner([
            'connection up' => ['output' => '', 'exit' => 4, 'stderr' => 'Error: Connection activation failed.'],
            'connection delete' => '',
        ]);
        $supervisor = $this->supervisor($runner, 2);

        $this->assertSame(SupervisorState::Restarted, $supervisor->tick());

        $this->expectException(CommandFailed::class);

        $supervisor->tick();
    }

    #[Test]
    public function stop_takes_the_hotspot_down_only_when_it_is_up(): void
    {
        $down = $this->runner();
        $this->supervisor($down)->stop();
        $this->assertNotContains('nmcli connection down Hotspot', self::commands($down));

        $up = $this->runner(['connection show --active' => "Hotspot\n"]);
        $this->supervisor($up)->stop();
        $this->assertContains('nmcli connection down Hotspot', self::commands($up));
    }
}
