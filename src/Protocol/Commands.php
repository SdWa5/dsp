<?php

namespace App\Protocol;

/**
 * Frame builders for every editor function. All values are raw protocol values,
 * see Scale for the conversions. Multi-byte values are little-endian.
 */
final class Commands
{
    public const PRESET_NAME_LENGTH = 14;
    public const CHANNEL_NAME_LENGTH = 8;
    public const PIN_LENGTH = 4;

    public static function init(): Frame
    {
        return Frame::request(Opcode::Init);
    }

    /** Sent by the editor after link changes. */
    public static function activate(): Frame
    {
        return Frame::request(Opcode::Activate);
    }

    public static function gain(int $channel, int $raw): Frame
    {
        if ($raw < 0 || $raw > Scale::GAIN_MAX) {
            throw new \OutOfRangeException(\sprintf('Gain raw value must be 0..%d, got %d.', Scale::GAIN_MAX, $raw));
        }

        return Frame::request(Opcode::Gain, \chr($channel).self::u16($raw));
    }

    public static function gainDb(int $channel, float $db): Frame
    {
        return self::gain($channel, Scale::dbToGain($db));
    }

    public static function mute(int $channel, bool $on): Frame
    {
        return Frame::request(Opcode::Mute, \chr($channel).\chr((int) $on));
    }

    public static function phase(int $channel, bool $inverted): Frame
    {
        return Frame::request(Opcode::Phase, \chr($channel).\chr((int) $inverted));
    }

    /**
     * Matrix routing of an output (0x08–0x0F), or the source selection of the Aux (0x12).
     * Bit 0 = In1 … bit 7 = In8; output masks also use bit 8 for Aux. Aux has input bits only.
     */
    public static function routing(int $channel, int $mask): Frame
    {
        return Frame::request(Opcode::Routing, \chr($channel).self::u16($mask));
    }

    /**
     * @param int $slope 0 = bypass, 1..20 = Labels::SLOPES
     */
    public static function highPass(int $channel, int $freqIndex, int $slope): Frame
    {
        return Frame::request(Opcode::HighPass, \chr($channel).self::u16($freqIndex).\chr($slope));
    }

    /**
     * @param int $slope 0 = bypass, 1..20 = Labels::SLOPES
     */
    public static function lowPass(int $channel, int $freqIndex, int $slope): Frame
    {
        return Frame::request(Opcode::LowPass, \chr($channel).self::u16($freqIndex).\chr($slope));
    }

    /**
     * Parametric EQ band: inputs and Aux have 5 bands (0..4), outputs 7 (0..6).
     */
    public static function peq(int $channel, int $band, int $gainRaw, int $freqIndex, int $qIndex, int $type, bool $bypass): Frame
    {
        return Frame::request(
            Opcode::Peq,
            \chr($channel).\chr($band).self::u16($gainRaw).self::u16($freqIndex).\chr($qIndex).\chr($type).\chr((int) $bypass),
        );
    }

    /** EQ bypass of the whole channel. */
    public static function eqBypass(int $channel, bool $on): Frame
    {
        return Frame::request(Opcode::EqBypass, \chr($channel).\chr((int) $on));
    }

    /** Noise gate, the 8x8 has no hold time. */
    public static function gate(int $channel, int $attack, int $release, int $threshold): Frame
    {
        return Frame::request(Opcode::Gate, \chr($channel).self::u16($attack).self::u16($release).self::u16($threshold));
    }

    public static function compressor(int $channel, int $ratio, int $knee, int $attack, int $release, int $threshold): Frame
    {
        return Frame::request(
            Opcode::Compressor,
            \chr($channel).\chr($ratio).\chr($knee).self::u16($attack).self::u16($release).self::u16($threshold),
        );
    }

    public static function delay(int $channel, int $samples): Frame
    {
        return Frame::request(Opcode::Delay, \chr($channel).self::u16($samples));
    }

    /** Delay display unit: 0 ms, 1 m, 2 ft. */
    public static function delayUnit(int $unit): Frame
    {
        return Frame::request(Opcode::DelayUnit, \chr($unit));
    }

    /** Channel link: the master gets the OR mask of the group, slaves get 0. */
    public static function link(int $channel, int $flags): Frame
    {
        return Frame::request(Opcode::Link, \chr($channel).\chr($flags));
    }

    /** The device copies all parameters of $source to $target, including gain and mute. */
    public static function copyChannel(int $source, int $target): Frame
    {
        return Frame::request(Opcode::CopyChannel, \chr($source).\chr($target));
    }

    public static function channelName(int $channel, string $name): Frame
    {
        return Frame::request(Opcode::ChannelName, \chr($channel).self::ascii($name, self::CHANNEL_NAME_LENGTH, "\x00"));
    }

    /** Input mode: 0 Line, 1..3 Mic 0/+1/+2 dB. */
    public static function inputMode(int $channel, int $mode): Frame
    {
        return Frame::request(Opcode::InputMode, \chr($channel).\chr($mode));
    }

    /** +48 V phantom power. */
    public static function phantom(int $channel, bool $on): Frame
    {
        return Frame::request(Opcode::Phantom, \chr($channel).\chr((int) $on));
    }

    /** Feedback suppression: 0 off, 1..4. */
    public static function fbq(int $channel, int $level): Frame
    {
        return Frame::request(Opcode::Fbq, \chr($channel).\chr($level));
    }

    /**
     * AutoMix ($channel = Channel::AUX) or Camera ($channel = Channel::CAMERA).
     */
    public static function mixFunction(int $channel, int $attack, int $release, int $threshold, int $select, bool $on): Frame
    {
        return Frame::request(
            Opcode::MixFunction,
            \chr($channel).self::u16($attack).self::u16($release).self::u16($threshold).self::u16($select).self::u16((int) $on),
        );
    }

    /** AutoMix slope: 0 = -3 dB … 3 = -6 dB. */
    public static function autoMixSlope(int $index): Frame
    {
        return Frame::request(Opcode::AutoMixSlope, \chr($index));
    }

    public static function effect(bool $on, int $gain): Frame
    {
        return Frame::request(Opcode::Effect, \chr((int) $on).\chr($gain));
    }

    public static function echo(int $predelay, int $delay, int $repeat, int $level): Frame
    {
        return Frame::request(Opcode::Echo, self::u16($predelay).self::u16($delay).self::u16($repeat).self::u16($level));
    }

    public static function reverb(int $predelay, int $reflect, int $time, int $level): Frame
    {
        return Frame::request(Opcode::Reverb, self::u16($predelay).self::u16($reflect).self::u16($time).self::u16($level));
    }

    /**
     * Sub control: $io 0 = inputs, 1 = outputs.
     */
    public static function subControl(int $io, int $gainPercent, bool $muted, int $mask, int $extra = 0): Frame
    {
        return Frame::request(Opcode::SubControl, \chr($io).\chr($gainPercent).\chr((int) $muted).\chr($mask & 0xFF).\chr($extra));
    }

    /**
     * Group control: groups 0–3 are inputs, 4–7 outputs.
     */
    public static function group(int $index, int $gainPercent, bool $muted, int $mask): Frame
    {
        return Frame::request(Opcode::Group, \chr($index).\chr($gainPercent).\chr((int) $muted).\chr($mask & 0xFF));
    }

    public static function readGroups(): Frame
    {
        return Frame::request(Opcode::Groups, "\x00");
    }

    /**
     * Test tone: 0 analog input, 1 pink, 2 white, 3 sine at Labels::TONE_FREQUENCIES[$freqIndex].
     * Plays on every routed output.
     */
    public static function testTone(int $mode, int $freqIndex = 0): Frame
    {
        return Frame::request(Opcode::TestTone, \chr($mode).\chr($freqIndex));
    }

    /** Working status: 0 Memorize, 1 Forget. */
    public static function workingStatus(int $mode): Frame
    {
        return Frame::request(Opcode::WorkingStatus, \chr($mode));
    }

    public static function deviceId(int $id): Frame
    {
        return Frame::request(Opcode::DeviceId, \chr($id));
    }

    public static function readConfigPage(int $page): Frame
    {
        return Frame::request(Opcode::ReadConfigPage, \chr($page));
    }

    public static function readPresetName(int $slot): Frame
    {
        return Frame::request(Opcode::PresetName, \chr($slot));
    }

    public static function meters(): Frame
    {
        return Frame::request(Opcode::Meters);
    }

    /**
     * Recalls a preset by editor number (0 = F00, 1 = U01 …). Replaces the live state. 4x4 semantics.
     */
    public static function loadPreset(int $number): Frame
    {
        return Frame::request(Opcode::LoadPreset, \chr($number));
    }

    /**
     * Stores the live state as preset U01..U20. Send presetName() first. 4x4 semantics.
     */
    public static function storePreset(int $number): Frame
    {
        if ($number < 1 || $number > 20) {
            throw new \OutOfRangeException(\sprintf('Only U01..U20 can be stored, got %d. F00 is the factory default.', $number));
        }

        return Frame::request(Opcode::StorePreset, \chr($number));
    }

    public static function presetName(string $name): Frame
    {
        return Frame::request(Opcode::StorePresetName, self::ascii($name, self::PRESET_NAME_LENGTH, ' '));
    }

    public static function deviceStatus(): Frame
    {
        return Frame::request(Opcode::DeviceStatus);
    }

    /** 4x4 semantics. */
    public static function unlock(string $pin): Frame
    {
        return Frame::request(Opcode::Unlock, "\x00".self::pin($pin));
    }

    /** Sets the PIN and locks the device immediately. 4x4 semantics. */
    public static function setPin(string $pin): Frame
    {
        return Frame::request(Opcode::SetPin, self::pin($pin));
    }

    public static function u16(int $value): string
    {
        return pack('v', $value);
    }

    private static function ascii(string $text, int $length, string $pad): string
    {
        // One "?" per character for valid UTF-8, one per byte otherwise
        $ascii = preg_replace('/[^\x20-\x7E]/u', '?', $text) ?? preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';

        return str_pad(substr($ascii, 0, $length), $length, $pad);
    }

    private static function pin(string $pin): string
    {
        if (!preg_match('/^\d{1,4}$/', $pin)) {
            throw new \InvalidArgumentException('The PIN must be 1 to 4 digits.');
        }

        return str_pad($pin, self::PIN_LENGTH, '0');
    }
}
