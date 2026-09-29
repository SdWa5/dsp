<?php

namespace App\Tests\Model;

use App\Model\Device;
use App\Model\DeviceState;
use App\Model\InputChannel;
use App\Model\OutputChannel;
use App\Protocol\Channel;
use App\Protocol\Commands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceStateTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../fixtures/config.bin';

    private static function fixture(): string
    {
        return (string) file_get_contents(self::FIXTURE);
    }

    private const FACTORY = __DIR__.'/../../resources/factory_config.bin';

    /**
     * The bits an upload writes: every byte except the ones no command reaches, and in the footer bit masks
     * only the bits of existing channels and bands.
     */
    private static function writable(): string
    {
        $mask = str_repeat("\xFF", Device::CONFIG_SIZE);
        $clear = static function (int $offset, int $keep = 0) use (&$mask): void {
            $mask[$offset] = \chr($keep);
        };
        // Header (FF FF and the preset name, written only by storing a preset)
        foreach (range(0, 15) as $o) {
            $clear($o);
        }
        for ($i = 0; $i < 8; ++$i) {
            foreach ([8, 11, 59, 61] as $o) {
                $clear(Device::INPUT_BASE + InputChannel::SIZE * $i + $o);
            }
            foreach ([71, 75] as $o) {
                $clear(Device::OUTPUT_BASE + OutputChannel::SIZE * $i + $o);
            }
        }
        foreach ([13, 14, 15, 17] as $o) {
            $clear(Device::AUX_BASE + $o);
        }
        $f = Device::FOOTER_BASE;
        // Mute: inputs, Aux, outputs. EQ bypass: inputs, outputs, Aux
        foreach ([0 => 0xFF, 2 => 0x01, 4 => 0xFF, 56 => 0xFF, 58 => 0xFF, 60 => 0x01] as $o => $bits) {
            $clear($f + $o, $bits);
            $clear($f + $o + 1);
        }
        // PEQ band bypass: 5 bands per input, 5 for the Aux, 7 per output
        for ($i = 0; $i < 8; ++$i) {
            $clear($f + 6 + 2 * $i, 0x1F);
            $clear($f + 7 + 2 * $i);
            $clear($f + 24 + 2 * $i, 0x7F);
            $clear($f + 25 + 2 * $i);
        }
        $clear($f + 22, 0x1F);
        $clear($f + 23);
        // Test tone mode and frequency (left out on purpose) and bytes without a known command
        foreach ([48, 49, 50, 51, 53, 54, 55, 62] as $o) {
            $clear($f + $o);
        }

        return $mask;
    }

    private static function config(string $name): string
    {
        return match ($name) {
            'backup' => self::fixture(),
            'factory' => (string) file_get_contents(self::FACTORY),
            'zeros' => str_repeat("\x00", Device::CONFIG_SIZE),
            'FF' => str_repeat("\xFF", Device::CONFIG_SIZE),
            default => throw new \InvalidArgumentException($name),
        };
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uploads(): iterable
    {
        yield 'backup onto a factory reset' => ['backup', 'factory'];
        yield 'factory onto a configured unit' => ['factory', 'backup'];
        yield 'backup onto zeros' => ['backup', 'zeros'];
        yield 'backup onto FF' => ['backup', 'FF'];
        yield 'factory onto FF' => ['factory', 'FF'];
    }

    /**
     * Uploading a preset (every write of allFrames) must rebuild every writable bit of the configuration,
     * whatever the device held before.
     */
    #[DataProvider('uploads')]
    public function testUploadRebuildsEveryWritableBit(string $sourceName, string $baseName): void
    {
        $source = self::config($sourceName);
        $base = self::config($baseName);
        $original = Device::parse($source);
        [$original->groups[2]->gain, $original->groups[2]->mute, $original->groups[2]->mask] = [80, true, 0x05];
        $original->inputs[1]->phantom = true;
        $state = new DeviceState($base);
        foreach ($original->allFrames() as $frame) {
            $state->apply($frame);
        }

        $mask = self::writable();
        $diff = [];
        for ($i = 0; $i < Device::CONFIG_SIZE; ++$i) {
            if ((\ord($state->config()[$i]) & \ord($mask[$i])) !== (\ord($source[$i]) & \ord($mask[$i]))) {
                $diff[] = $i;
            }
        }
        self::assertSame([], $diff, 'Offsets the upload does not rebuild');
        self::assertEquals($original->toArray()['groups'], $state->toDevice()->toArray()['groups']);
        self::assertTrue($state->toDevice()->inputs[1]->phantom);
        self::assertNull($state->toDevice()->inputs[0]->phantom);
    }

    public function testFactoryReadOutIsAFactoryReset(): void
    {
        self::assertSame('Default Preset', Device::parse((string) file_get_contents(self::FACTORY))->presetName);
    }

    public function testRemembersValuesTheConfigurationDoesNotCarry(): void
    {
        $state = new DeviceState(self::fixture());
        $state->apply(Commands::phantom(2, true));
        $state->apply(Commands::workingStatus(1));
        $state->apply(Commands::deviceId(7));
        $state->replaceConfig(self::fixture());
        $device = $state->toDevice();

        self::assertTrue($device->inputs[2]->phantom);
        self::assertNull($device->inputs[3]->phantom);
        self::assertSame(1, $device->workingStatus);
        self::assertSame(7, $device->deviceId);
    }

    public function testTheTestToneLivesInTheConfiguration(): void
    {
        $state = new DeviceState(self::fixture());
        $state->apply(Commands::testTone(3, 12));

        self::assertSame([3, 12], [$state->toDevice()->toneMode, $state->toDevice()->toneFreq]);
        self::assertSame("\x03\x00\x0C", substr($state->config(), Device::FOOTER_BASE + 48, 3));
        // A fresh read brings back what the device holds, the frequency as well as the mode
        $state->replaceConfig(self::fixture());
        self::assertSame([2, 0], [$state->toDevice()->toneMode, $state->toDevice()->toneFreq]);
    }

    public function testCopyChannelCopiesBlockAndMute(): void
    {
        $state = new DeviceState(self::fixture());
        $state->apply(Commands::gain(Channel::output(1), 123));
        $state->apply(Commands::mute(Channel::output(1), true));
        $state->apply(Commands::mute(Channel::output(4), false));
        $state->apply(Commands::copyChannel(Channel::output(1), Channel::output(4)));
        $device = $state->toDevice();

        self::assertSame(123, $device->outputs[3]->gain);
        self::assertTrue($device->outputs[3]->mute);
        self::assertSame($device->outputs[0]->toArray(), $device->outputs[3]->toArray());
    }

    public function testCopyBetweenInputAndOutputIsIgnored(): void
    {
        $state = new DeviceState(self::fixture());
        $state->apply(Commands::copyChannel(Channel::input(1), Channel::output(1)));

        self::assertSame(self::fixture(), $state->config());
    }

    public function testPresets(): void
    {
        $state = new DeviceState(self::fixture(), activePreset: 2);
        $state->apply(Commands::presetName('Stage'));
        $state->apply(Commands::storePreset(7));
        $state->apply(Commands::loadPreset(7));
        $device = $state->toDevice();

        self::assertSame('Stage', $device->presetNames[6]);
        self::assertSame(7, $device->activePreset);
        self::assertCount(DeviceState::PRESET_SLOTS, $device->presetNames);
    }

    public function testGroupsAndUnknownCommands(): void
    {
        $state = new DeviceState(self::fixture());
        $state->apply(Commands::group(5, 80, true, 0b11));
        $state->apply(Commands::meters());
        $state->apply(Commands::activate());

        $group = $state->toDevice()->groups[5];
        self::assertSame([80, true, 0b11], [$group->gain, $group->mute, $group->mask]);
        self::assertSame(self::fixture(), $state->config());
    }

    public function testStoringAPresetBehavesLikeTheDevice(): void
    {
        $state = new DeviceState(self::fixture());
        $state->presetStored = array_fill(0, 20, false);
        $state->apply(Commands::presetName('TEST'));
        $state->apply(Commands::storePreset(20));

        self::assertSame('TEST', $state->presetName(19));
        self::assertSame([19], array_keys(array_filter($state->presetStored ?? [])));
        self::assertSame(20, $state->activePreset);
        self::assertSame('TEST', $state->toDevice()->presetName);
        self::assertSame(substr(self::fixture(), 16), substr($state->config(), 16));
    }

    public function testRejectsShortConfiguration(): void
    {
        $this->expectException(\LengthException::class);

        new DeviceState('short');
    }
}
