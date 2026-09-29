<?php

namespace App\Tests\Client;

use App\Client\DspException;
use App\Client\Matrix;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Opcode;
use App\Tests\Support\FakeDsp;
use PHPUnit\Framework\TestCase;

final class MatrixTest extends TestCase
{
    public function testReads(): void
    {
        $dsp = new Matrix(new FakeDsp(), 0.01);

        self::assertSame('8x8Matrix V010', $dsp->info());
        self::assertSame('192.168.68.10', $dsp->ipAddress());
        self::assertSame(2, $dsp->activePreset());
        self::assertSame('Bulgaria26', $dsp->presetName(1));
        $config = $dsp->config();
        self::assertSame(26 * 50, \strlen($config));
        self::assertSame(1, \ord($config[50]));
    }

    public function testWrites(): void
    {
        $fake = new FakeDsp();
        $dsp = new Matrix($fake, 0.01);

        $dsp->setGain('in1', -6);
        $dsp->mute('out1');
        $dsp->route(3, [1, 2]);

        self::assertSame([
            Commands::gain(0, 220)->hex(),
            Commands::mute(8, true)->hex(),
            Commands::routing(0x0A, 0b11)->hex(),
        ], array_map(static fn (Frame $f): string => $f->hex(), $fake->sent));
    }

    public function testPresetOccupancy(): void
    {
        $slots = (new Matrix(new FakeDsp(), 0.01))->presetOccupancy();

        self::assertCount(20, $slots);
        self::assertSame([19], array_keys(array_filter($slots)));
        self::assertSame([0], array_keys(array_filter(Matrix::parseOccupancy("\x01"))));
    }

    public function testPresetRecallAndStoreWaitLongerForTheAck(): void
    {
        $fake = new FakeDsp();
        $dsp = new Matrix($fake, 0.01);

        $dsp->write(Commands::mute(8, true));
        self::assertLessThanOrEqual(0.01, max([0.0, ...$fake->timeouts]));
        foreach ([Commands::loadPreset(20), Commands::storePreset(20)] as $frame) {
            $fake->timeouts = [];
            $dsp->write($frame);
            self::assertGreaterThan(2.9, max([0.0, ...$fake->timeouts]));
        }
    }

    public function testTimeoutRetriesWithReinit(): void
    {
        $silent = new FakeDsp(silent: true);

        try {
            (new Matrix($silent, 0.01, 1))->info();
            self::fail('Expected a DspException.');
        } catch (DspException $e) {
            self::assertStringContainsString('No reply', $e->getMessage());
        }
        self::assertSame(
            [Opcode::DeviceInfo->value, Opcode::Init->value, Opcode::DeviceInfo->value],
            array_map(static fn (Frame $f): int => $f->cmd, $silent->sent),
        );
    }

    public function testForeignTrafficDoesNotBlockForever(): void
    {
        $this->expectException(DspException::class);

        (new Matrix(new FakeDsp(silent: true, chatty: true), 0.05, 0))->info();
    }

    public function testMuteStateFromConfig(): void
    {
        $config = str_repeat("\x00", 26 * 50 - 23);
        $base = 0x18 * 50;
        $config = substr_replace($config, pack('v', 0x0003), $base + 14, 2);
        $config = substr_replace($config, pack('v', 0x00C0), $base + 18, 2);

        $state = (new Matrix(new FakeDsp()))->muteState($config);

        self::assertSame(['in1', 'in2', 'out7', 'out8'], array_keys(array_filter($state)));
    }

    public function testParseMeters(): void
    {
        // Read from the device: a muted input, an idle input at 0 dB gain, the 1 kHz test tone and pink noise at
        // unity gain. The Aux bytes are missing.
        $data = (string) hex2bin('ad05b0013618d23b6d043d00'.str_repeat('ad05c4', 12));

        $levels = Matrix::parseMeters($data);

        self::assertCount(17, $levels);
        self::assertSame([-90.0, -90.0, -27.85, -13.45], \array_slice($levels, 0, 4));
        self::assertSame(-90.0, $levels[16]);
        self::assertSame(-90.0, Matrix::parseMeters('')[0]);
    }
}
