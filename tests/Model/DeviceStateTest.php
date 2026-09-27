<?php

namespace App\Tests\Model;

use App\Model\Device;
use App\Model\DeviceState;
use App\Protocol\Channel;
use App\Protocol\Commands;
use PHPUnit\Framework\TestCase;

final class DeviceStateTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../fixtures/config.bin';

    private static function fixture(): string
    {
        return (string) file_get_contents(self::FIXTURE);
    }

    /**
     * Uploading a preset (every write of allFrames) into an empty configuration must give the same
     * channels as parsing the original: apply() writes each value where Device::parse() reads it.
     */
    public function testUploadFramesRebuildTheParsedConfiguration(): void
    {
        $original = Device::parse(self::fixture());
        $state = new DeviceState(str_repeat("\x00", Device::CONFIG_SIZE));
        foreach ($original->allFrames() as $frame) {
            $state->apply($frame);
        }
        $rebuilt = $state->toDevice();

        foreach (['inputs', 'outputs'] as $key) {
            self::assertEquals($original->toArray()[$key], $rebuilt->toArray()[$key], $key);
        }
        // The upload leaves out the Aux name, like the Python editor
        self::assertEquals(['name' => ''] + $original->aux->toArray(), $rebuilt->aux->toArray());
    }

    public function testRemembersValuesTheConfigurationDoesNotCarry(): void
    {
        $state = new DeviceState(self::fixture());
        $state->apply(Commands::phantom(2, true));
        $state->apply(Commands::workingStatus(1));
        $state->apply(Commands::testTone(3, 12));
        $state->apply(Commands::deviceId(7));
        self::assertSame(3, $state->toDevice()->toneMode);
        // The tone mode is part of the configuration, the frequency is not
        $state->replaceConfig(self::fixture());
        $device = $state->toDevice();

        self::assertTrue($device->inputs[2]->phantom);
        self::assertNull($device->inputs[3]->phantom);
        self::assertSame(1, $device->workingStatus);
        self::assertSame(12, $device->toneFreq);
        self::assertSame(7, $device->deviceId);
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

    public function testRejectsShortConfiguration(): void
    {
        $this->expectException(\LengthException::class);

        new DeviceState('short');
    }
}
