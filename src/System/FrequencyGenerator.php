<?php

namespace Sanchescom\WiFi\System;

/**
 * Class FrequencyGenerator.
 */
class FrequencyGenerator
{
    /**
     * @var array
     */
    protected static $frequencies = [];

    /**
     * Array description:
     * <code>
     * $frequencySettings = [
     *      [
     *          2412, // frequency starts from
     *          1,    // channel starts from
     *          14,   // channel ends till
     *          5,    // frequency step
     *          1,    // frequency increasing
     *      ],
     * ];
     * </code>.
     *
     * @var array[int][int]int
     */
    protected $frequencySettings = [
        [2412, 1, 14, 5, 1],
        [5180, 36, 64, 10, 2],
        [5500, 100, 144, 10, 2],
        [5745, 149, 161, 10, 2],
        [5825, 165, 173, 20, 4],
    ];

    /**
     * @param int $channel
     *
     * @return mixed
     */
    public function getFrequencyForChannel(int $channel)
    {
        return $this->generateFrequencies()[$channel];
    }

    /**
     * @return array
     */
    protected function generateFrequencies(): array
    {
        if (empty(self::$frequencies)) {
            foreach ($this->frequencySettings as $frequencySetting) {
                $this->setGeneratedFrequencies($frequencySetting);
            }
        }

        return self::$frequencies;
    }

    /**
     * @param array $frequencySetting
     */
    protected function setGeneratedFrequencies(array $frequencySetting): void
    {
        list(
            $frequencyStart,
            $channelStart,
            $channelEnd,
            $frequencyStep,
            $frequencyIncreasing
            ) = $frequencySetting;

        for ($i = $channelStart; $i <= $channelEnd; $i += $frequencyIncreasing) {
            self::$frequencies[$i] = $frequencyStart;

            $frequencyStart += $frequencyStep;
        }
    }
}