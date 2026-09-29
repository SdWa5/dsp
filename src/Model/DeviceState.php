<?php

namespace App\Model;

use App\Protocol\Channel;
use App\Protocol\Frame;
use App\Protocol\Opcode;

/**
 * Mutable device state as raw bytes: the configuration, the group control block and the preset names,
 * plus the values the configuration does not carry (+48V, working status, device ID).
 *
 * apply() takes a write command and changes the bytes the way the device does, so a cached state stays
 * current without reading the configuration again. The simulated device and the bridge both build on it.
 */
final class DeviceState
{
    private const F = Device::FOOTER_BASE;
    public const PRESET_SLOTS = 21;

    private string $config;
    private string $groups;
    /** @var array<int, string> name slots 0..20 */
    private array $presetNames;
    private string $pendingPresetName = '';
    /** @var list<bool>|null which of U01..U20 hold a stored preset, null until read */
    public ?array $presetStored = null;
    /** @var array<int, bool> input index => +48V, only once set */
    private array $phantom = [];
    private ?int $workingStatus = null;
    private ?int $deviceId = null;

    /**
     * @param array<int, string> $presetNames
     */
    public function __construct(
        string $config,
        ?string $groups = null,
        array $presetNames = [],
        public ?int $activePreset = null,
        public string $ip = '',
    ) {
        if (\strlen($config) < Device::CONFIG_SIZE) {
            throw new \LengthException(\sprintf('Configuration must be %d bytes, got %d.', Device::CONFIG_SIZE, \strlen($config)));
        }
        $this->config = substr($config, 0, Device::CONFIG_SIZE);
        $this->groups = str_pad($groups ?? str_repeat("\x64\x00\x00", 8), 24, "\x00");
        $this->presetNames = array_replace(array_fill(0, self::PRESET_SLOTS, ''), \array_slice($presetNames, 0, self::PRESET_SLOTS));
    }

    public function config(): string
    {
        return $this->config;
    }

    public function groups(): string
    {
        return $this->groups;
    }

    public function presetName(int $slot): string
    {
        return $this->presetNames[$slot] ?? '';
    }

    /**
     * Replaces the configuration after a fresh read, keeping the remembered values.
     */
    public function replaceConfig(string $config, ?string $groups = null): void
    {
        $this->config = substr(str_pad($config, Device::CONFIG_SIZE, "\x00"), 0, Device::CONFIG_SIZE);
        if (null !== $groups) {
            $this->groups = str_pad($groups, 24, "\x00");
        }
    }

    /**
     * @param array<int, string> $names
     */
    public function replacePresetNames(array $names): void
    {
        $this->presetNames = array_replace(array_fill(0, self::PRESET_SLOTS, ''), \array_slice($names, 0, self::PRESET_SLOTS));
    }

    public function toDevice(): Device
    {
        $device = Device::parse($this->config);
        $device->setGroups($this->groups);
        foreach ($this->phantom as $input => $on) {
            $device->inputs[$input]->phantom = $on;
        }
        $device->workingStatus = $this->workingStatus;
        $device->deviceId = $this->deviceId ?? $device->deviceId;
        $device->activePreset = $this->activePreset;
        $device->presetNames = array_values($this->presetNames);
        $device->presetStored = $this->presetStored;
        $device->ip = $this->ip;

        return $device;
    }

    /**
     * Applies a write command. Commands without a known effect on the state are ignored.
     */
    public function apply(Frame $frame): void
    {
        $op = $frame->opcode();
        if (null === $op) {
            return;
        }
        $d = $frame->data;
        $byte = static fn (int $i): int => \ord($d[$i] ?? "\x00");
        $u16 = static fn (int $i): int => $byte($i) | $byte($i + 1) << 8;
        $ch = $byte(0);
        $in = Channel::isInput($ch) ? Device::INPUT_BASE + InputChannel::SIZE * $ch : null;
        $out = Channel::isOutput($ch) ? Device::OUTPUT_BASE + OutputChannel::SIZE * ($ch - 8) : null;
        $aux = Channel::AUX === $ch ? Device::AUX_BASE : null;
        // Offset inside the input / output / Aux block, null where the block has no such field
        $at = static fn (?int $inputOffset, ?int $outputOffset, ?int $auxOffset = null): ?int => match (true) {
            null !== $in => null === $inputOffset ? null : $in + $inputOffset,
            null !== $out => null === $outputOffset ? null : $out + $outputOffset,
            null !== $aux => null === $auxOffset ? null : $aux + $auxOffset,
            default => null,
        };
        $maskBit = null !== $in || null !== $out ? $ch % 8 : 0;
        $maskOffset = static fn (int $inputs, int $outputs, int $auxiliary): ?int => match (true) {
            null !== $in => self::F + $inputs,
            null !== $out => self::F + $outputs,
            null !== $aux => self::F + $auxiliary,
            default => null,
        };

        match ($op) {
            Opcode::Gain => $this->put($at(56, 68, 92), substr($d, 1, 2)),
            Opcode::Mute => $this->setBit($maskOffset(0, 4, 2), $maskBit, 0 !== $byte(1)),
            Opcode::Phase => $this->put($at(58, 70), \chr($byte(1))),
            Opcode::Routing => $this->put($at(null, 8, 8), substr($d, 1, 2)),
            Opcode::ChannelName => $this->put($at(0, 0, 0), substr($d, 1, 8)),
            Opcode::Delay => $this->put($at(null, 72), substr($d, 1, 2)),
            Opcode::HighPass => $this->filter($at(48, 10), $u16(1), $byte(3)),
            Opcode::LowPass => $this->filter($at(52, 14), $u16(1), $byte(3)),
            Opcode::Peq => $this->peq($at(18, 18, 38), $maskOffset(6 + 2 * $ch, 24 + 2 * ($ch - 8), 22), $byte(1), substr($d, 2, 6), 0 !== $byte(8)),
            Opcode::Gate => $this->put($at(12, null), substr($d, 1, 6)),
            Opcode::Compressor => $this->put($at(null, 60, 84), substr($d, 1, 8)),
            Opcode::EqBypass => $this->setBit($maskOffset(56, 58, 60), $maskBit, 0 !== $byte(1)),
            Opcode::InputMode => $this->put($at(9, null), \chr($byte(1))),
            Opcode::Fbq => $this->put($at(10, null), \chr($byte(1))),
            Opcode::Link => $this->put($at(60, 74), \chr($byte(1))),
            Opcode::Phantom => null !== $in ? $this->phantom[$ch] = 0 !== $byte(1) : null,
            Opcode::DelayUnit => $this->put(self::F + 52, \chr($byte(0))),
            Opcode::TestTone => $this->testTone($byte(0), $byte(1)),
            Opcode::Effect => $this->put(Device::AUX_BASE + 10, \chr($byte(0))."\x00".\chr($byte(1))),
            Opcode::Echo => $this->put(Device::AUX_BASE + 68, substr($d, 0, 8)),
            Opcode::Reverb => $this->put(Device::AUX_BASE + 76, substr($d, 0, 8)),
            Opcode::AutoMixSlope => $this->put(Device::AUX_BASE + 16, \chr($byte(0))),
            Opcode::MixFunction => $this->put(match ($ch) {
                Channel::AUX => Device::AUX_BASE + 18,
                Channel::CAMERA => Device::AUX_BASE + 28,
                default => null,
            }, substr($d, 1, 10)),
            Opcode::SubControl => $this->put(self::F + (0 === $ch ? 40 : 44), substr($d, 1, 4)),
            Opcode::Group => $ch < 8 ? $this->groups = substr_replace($this->groups, substr($d, 1, 3), 3 * $ch, 3) : null,
            Opcode::CopyChannel => $this->copy($ch, $byte(1)),
            Opcode::WorkingStatus => $this->workingStatus = $byte(0),
            Opcode::DeviceId => $this->deviceId = $byte(0),
            Opcode::StorePresetName => $this->pendingPresetName = rtrim(substr($d, 0, 14)),
            Opcode::StorePreset => $ch >= 1 && $ch <= 20 ? $this->storePreset($ch) : null,
            Opcode::LoadPreset => $this->activePreset = $ch,
            default => null,
        };
    }

    /**
     * The device names the slot, marks it stored, makes it the active preset and puts the name into the
     * header of the live configuration (checked on the device, 2026-09-28).
     */
    private function storePreset(int $number): void
    {
        $this->presetNames[$number - 1] = $this->pendingPresetName;
        if (null !== $this->presetStored) {
            $this->presetStored = array_values(array_replace($this->presetStored, [$number - 1 => true]));
        }
        $this->activePreset = $number;
        $this->put(2, str_pad(substr($this->pendingPresetName, 0, 14), 14));
    }

    private function put(?int $offset, string $bytes): void
    {
        if (null !== $offset && '' !== $bytes) {
            $this->config = substr_replace($this->config, $bytes, $offset, \strlen($bytes));
        }
    }

    private function setBit(?int $offset, int $bit, bool $on): void
    {
        if (null === $offset) {
            return;
        }
        $mask = Bytes::u16($this->config, $offset);
        $this->put($offset, pack('v', $on ? $mask | 1 << $bit : $mask & ~(1 << $bit)));
    }

    private function filter(?int $offset, int $freq, int $slope): void
    {
        if (null !== $offset) {
            $this->put($offset, \chr($slope)."\x00".pack('v', $freq));
        }
    }

    private function peq(?int $base, ?int $bypassOffset, int $band, string $values, bool $bypass): void
    {
        if (null !== $base) {
            $this->put($base + 6 * $band, $values);
            $this->setBit($bypassOffset, $band, $bypass);
        }
    }

    /**
     * The device keeps the mode at footer +48 and the sine frequency at +50 (checked on the device, 2026-09-29).
     */
    private function testTone(int $mode, int $freq): void
    {
        $this->put(self::F + 48, \chr($mode));
        $this->put(self::F + 50, \chr($freq));
    }

    /**
     * The device copies every parameter of the channel block including gain, plus the mute bit.
     */
    private function copy(int $source, int $target): void
    {
        foreach ([[Channel::isInput(...), Device::INPUT_BASE, InputChannel::SIZE, 0, 0], [Channel::isOutput(...), Device::OUTPUT_BASE, OutputChannel::SIZE, 8, 4]] as [$is, $base, $size, $first, $muteOffset]) {
            if (!$is($source) || !$is($target)) {
                continue;
            }
            $this->put($base + $size * ($target - $first), substr($this->config, $base + $size * ($source - $first), $size));
            $muted = 1 === (Bytes::u16($this->config, self::F + $muteOffset) >> ($source - $first) & 1);
            $this->setBit(self::F + $muteOffset, $target - $first, $muted);
        }
    }
}
