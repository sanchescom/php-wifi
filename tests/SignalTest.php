<?php

namespace Sanchescom\WiFi\Test;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\Test\Linux\Mocks\NetworksCommand;

class SignalTest extends BaseTestCase
{
    private function network(): AbstractNetwork
    {
        return new class(new NetworksCommand()) extends AbstractNetwork {
            public function connect(string $password, string $device): void
            {
            }

            public function disconnect(string $device): void
            {
            }

            public function createFromArray(array $network): AbstractNetwork
            {
                return $this;
            }

            public function fromQuality(float $quality): self
            {
                $this->setSignalFromQuality($quality);

                return $this;
            }

            public function fromDbm(float $dbm): self
            {
                $this->setSignalFromDbm($dbm);

                return $this;
            }
        };
    }

    #[Test]
    public function quality_is_converted_to_dbm(): void
    {
        $network = $this->network()->fromQuality(72);

        $this->assertSame(72.0, $network->quality);
        $this->assertSame(-64.0, $network->dbm);
    }

    #[Test]
    public function dbm_is_converted_to_quality(): void
    {
        $network = $this->network()->fromDbm(-59);

        $this->assertSame(-59.0, $network->dbm);
        $this->assertSame(82.0, $network->quality);
    }

    #[Test]
    public function quality_is_clamped_to_percent_range(): void
    {
        $this->assertSame(100.0, $this->network()->fromQuality(390)->quality);
        $this->assertSame(0.0, $this->network()->fromQuality(-5)->quality);
        $this->assertSame(100.0, $this->network()->fromDbm(-20)->quality);
        $this->assertSame(0.0, $this->network()->fromDbm(-130)->quality);
    }
}
