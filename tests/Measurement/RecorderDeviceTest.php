<?php

namespace App\Tests\Measurement;

use App\Measurement\Recorder;
use App\Measurement\WavReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresEnvironmentVariable;
use PHPUnit\Framework\TestCase;

/**
 * Records from a real capture device, for example
 * SDWA5_DSP_TEST_CAPTURE=plughw:CARD=UMC1820,DEV=0 vendor/bin/phpunit --group device.
 */
#[Group('device')]
#[RequiresEnvironmentVariable('SDWA5_DSP_TEST_CAPTURE')]
final class RecorderDeviceTest extends TestCase
{
    public function testRecordsOneSecond(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sdwa5-dsp-capture-');
        try {
            (new Recorder((string) getenv('SDWA5_DSP_TEST_CAPTURE'), 1))->record(1, $path);
            $wav = WavReader::read($path);

            self::assertSame(Recorder::RATE, $wav['rate']);
            self::assertSame(2, $wav['channels']);
            self::assertEqualsWithDelta(Recorder::RATE, \count($wav['samples']), Recorder::RATE / 10);
            // An analog input always carries some noise
            self::assertGreaterThan(0.0, max([0.0, ...array_map('abs', $wav['samples'])]), 'Input 1 delivers digital silence.');
        } finally {
            @unlink($path);
        }
    }
}
