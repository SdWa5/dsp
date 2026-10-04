<?php

namespace App\Tests\Model;

use App\Model\FactoryDefaults;
use PHPUnit\Framework\TestCase;

final class FactoryDefaultsTest extends TestCase
{
    private const FACTORY = __DIR__.'/../../resources/factory_config.bin';

    public function testHoldsOnlyPresetContent(): void
    {
        $defaults = FactoryDefaults::fromFile(self::FACTORY);

        foreach (['raw', 'footer', 'preset_name', 'preset_names', 'preset_stored', 'ip', 'device_id', 'working_status', 'tone_mode'] as $key) {
            self::assertArrayNotHasKey($key, $defaults);
        }
        self::assertArrayNotHasKey('phantom', $defaults['inputs'][0]);
        self::assertArrayHasKey('eq_bypass', $defaults['inputs'][0]);
    }

    public function testFactoryValues(): void
    {
        $defaults = FactoryDefaults::fromFile(self::FACTORY);

        self::assertSame(1, $defaults['inputs'][0]['link']);
        self::assertSame(128, $defaults['inputs'][7]['link']);
        self::assertSame(280, $defaults['outputs'][0]['gain']);
        self::assertFalse($defaults['outputs'][0]['mute']);
        self::assertSame(['gain' => 100, 'mute' => false, 'mask' => 0], $defaults['groups'][7]);
        self::assertCount(7, $defaults['outputs'][0]['peq']);
        // The factory input gain mutes. The editor compares against 0 dB instead (referenceDefaults() in defaults.js)
        self::assertSame(0, $defaults['inputs'][0]['gain']);
        self::assertSame(280, $defaults['aux']['gain']);
    }

    public function testEveryChannelOfASideHasTheDefaultsOfItsFirst(): void
    {
        $defaults = FactoryDefaults::fromFile(self::FACTORY);
        foreach (['inputs', 'outputs'] as $side) {
            $first = array_diff_key($defaults[$side][0], array_flip(['name', 'route', 'link']));
            foreach ($defaults[$side] as $index => $channel) {
                self::assertSame($first, array_diff_key($channel, array_flip(['name', 'route', 'link'])), $side.' '.$index);
            }
        }
    }

    public function testMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);

        FactoryDefaults::fromFile(__DIR__.'/missing.bin');
    }
}
