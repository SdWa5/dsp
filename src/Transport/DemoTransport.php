<?php

namespace App\Transport;

use App\Model\Device;
use App\Protocol\Channel;
use App\Protocol\Frame;
use App\Protocol\Opcode;

/**
 * Simulated device for working without hardware. It answers like the 8x8, applies the write
 * commands with a known configuration offset to its own configuration and produces moving meters.
 */
final class DemoTransport implements TransportInterface
{
    private const F = Device::FOOTER_BASE;

    /** @var list<Frame> */
    private array $queue = [];
    private string $config;
    /** @var array<int, string> name slots 0..20 */
    private array $presetNames;
    private string $groups;
    private int $tick = 0;
    private string $pendingName = '';

    public function __construct(string $config, private readonly int $activePreset = 2)
    {
        if (\strlen($config) < Device::CONFIG_SIZE) {
            throw new \LengthException('Demo configuration must be 1277 bytes.');
        }
        $this->config = substr($config, 0, Device::CONFIG_SIZE);
        $this->presetNames = array_fill(0, 21, '');
        $this->presetNames[$activePreset - 1] = rtrim(substr($this->config, 2, 14), " \x00");
        $this->groups = str_repeat("\x64\x00\x00", 8);
    }

    public static function fromFile(string $path): self
    {
        $config = @file_get_contents($path);
        if (false === $config) {
            throw new TransportException(\sprintf('Cannot read demo configuration %s.', $path));
        }

        return new self($config);
    }

    public function send(Frame $frame): void
    {
        $reply = $this->answer($frame);
        if (null !== $reply) {
            $this->queue[] = $reply;
        }
    }

    public function receive(float $timeout): ?Frame
    {
        return array_shift($this->queue);
    }

    public function close(): void
    {
        $this->queue = [];
    }

    public function describe(): string
    {
        return 'demo';
    }

    public function config(): string
    {
        return $this->config;
    }

    private function answer(Frame $frame): ?Frame
    {
        $d = $frame->data;
        $byte = static fn (int $i): int => \ord($d[$i] ?? "\x00");
        $u16 = static fn (int $i): int => $byte($i) | $byte($i + 1) << 8;

        switch ($frame->opcode()) {
            case Opcode::Init:
                return Frame::reply(Opcode::Init, "\x15");
            case Opcode::DeviceInfo:
                return Frame::reply(Opcode::DeviceInfo, '8x8Matrix V010');
            case Opcode::IpAddress:
                return Frame::reply(Opcode::IpAddress, "\xC0\xA8\x44\x0A");
            case Opcode::ActivePreset:
                return Frame::reply(Opcode::ActivePreset, \chr($this->activePreset));
            case Opcode::PresetName:
                return Frame::reply(Opcode::PresetName, \chr($byte(0)).str_pad($this->presetNames[$byte(0)] ?? '', 14));
            case Opcode::ReadConfigPage:
                $page = substr(str_pad($this->config, 26 * 50, "\x00"), 50 * $byte(0), 50);

                return Frame::reply(Opcode::ConfigPage, \chr($byte(0)).($byte(0) < 25 ? $page : substr($page, 0, 27)));
            case Opcode::Groups:
                return Frame::reply(Opcode::Groups, $this->groups);
            case Opcode::Meters:
                return Frame::reply(Opcode::Meters, $this->meters());
            case Opcode::DeviceStatus:
                return Frame::reply(Opcode::DeviceStatus, str_repeat("\x00", 8));
            case null:
                return null;
            default:
                $this->apply($frame->opcode(), $byte, $u16, $d);

                return Frame::reply(Opcode::Ack);
        }
    }

    /**
     * @param \Closure(int): int $byte
     * @param \Closure(int): int $u16
     */
    private function apply(Opcode $op, \Closure $byte, \Closure $u16, string $d): void
    {
        $ch = $byte(0);
        $in = Channel::isInput($ch) ? Device::INPUT_BASE + 62 * $ch : null;
        $out = Channel::isOutput($ch) ? Device::OUTPUT_BASE + 76 * ($ch - 8) : null;
        $aux = Channel::AUX === $ch ? Device::AUX_BASE : null;

        match ($op) {
            Opcode::Gain => $this->put($in ? $in + 56 : ($out ? $out + 68 : ($aux ? $aux + 92 : null)), substr($d, 1, 2)),
            Opcode::Mute => $this->setBit(null !== $in ? self::F : (null !== $out ? self::F + 4 : self::F + 2), $in || $out ? $ch % 8 : 0, 0 !== $byte(1)),
            Opcode::Phase => $this->put(null !== $in ? $in + 58 : ($out ? $out + 70 : null), $d[1] ?? "\x00"),
            Opcode::Routing => $this->put($out ? $out + 8 : ($aux ? $aux + 8 : null), substr($d, 1, 2)),
            Opcode::ChannelName => $this->put($in ?? $out ?? $aux, substr($d, 1, 8)),
            Opcode::Delay => $this->put($out ? $out + 72 : null, substr($d, 1, 2)),
            Opcode::HighPass => $this->filter(null !== $in ? $in + 48 : ($out ? $out + 10 : null), $u16(1), $byte(3)),
            Opcode::LowPass => $this->filter(null !== $in ? $in + 52 : ($out ? $out + 14 : null), $u16(1), $byte(3)),
            Opcode::Peq => $this->peq($in, $out, $aux, $byte(1), substr($d, 2, 6), 0 !== $byte(8), $ch),
            Opcode::Gate => $this->put(null !== $in ? $in + 12 : null, substr($d, 1, 6)),
            Opcode::Compressor => $this->put($out ? $out + 60 : ($aux ? $aux + 84 : null), substr($d, 1, 8)),
            Opcode::EqBypass => $this->setBit(null !== $in ? self::F + 56 : (null !== $out ? self::F + 58 : self::F + 60), $in || $out ? $ch % 8 : 0, 0 !== $byte(1)),
            Opcode::InputMode => $this->put(null !== $in ? $in + 9 : null, $d[1] ?? "\x00"),
            Opcode::Fbq => $this->put(null !== $in ? $in + 10 : null, $d[1] ?? "\x00"),
            Opcode::Link => $this->put(null !== $in ? $in + 60 : ($out ? $out + 74 : null), $d[1] ?? "\x00"),
            Opcode::DelayUnit => $this->put(self::F + 52, $d[0] ?? "\x00"),
            Opcode::TestTone => $this->put(self::F + 48, $d[0] ?? "\x00"),
            Opcode::Effect => $this->put(Device::AUX_BASE + 10, ($d[0] ?? "\x00")."\x00".($d[1] ?? "\x00")),
            Opcode::Echo => $this->put(Device::AUX_BASE + 68, substr($d, 0, 8)),
            Opcode::Reverb => $this->put(Device::AUX_BASE + 76, substr($d, 0, 8)),
            Opcode::AutoMixSlope => $this->put(Device::AUX_BASE + 16, $d[0] ?? "\x00"),
            Opcode::MixFunction => $this->put(Channel::AUX === $ch ? Device::AUX_BASE + 18 : (Channel::CAMERA === $ch ? Device::AUX_BASE + 28 : null), substr($d, 1, 10)),
            Opcode::SubControl => $this->put(self::F + (0 === $ch ? 40 : 44), substr($d, 1, 4)),
            Opcode::Group => $this->groups = substr_replace($this->groups, substr($d, 1, 3), 3 * min($ch, 7), 3),
            Opcode::CopyChannel => $this->copy($ch, $byte(1)),
            Opcode::StorePresetName, Opcode::StorePreset, Opcode::LoadPreset => $this->preset($op, $byte(0), $d),
            default => null,
        };
    }

    private function put(?int $offset, string $bytes): void
    {
        if (null !== $offset) {
            $this->config = substr_replace($this->config, $bytes, $offset, \strlen($bytes));
        }
    }

    private function setBit(int $offset, int $bit, bool $on): void
    {
        $mask = \ord($this->config[$offset]) | \ord($this->config[$offset + 1]) << 8;
        $mask = $on ? $mask | 1 << $bit : $mask & ~(1 << $bit);
        $this->put($offset, pack('v', $mask));
    }

    private function filter(?int $offset, int $freq, int $slope): void
    {
        if (null !== $offset) {
            $this->put($offset, \chr($slope)."\x00".pack('v', $freq));
        }
    }

    private function peq(?int $in, ?int $out, ?int $aux, int $band, string $values, bool $bypass, int $ch): void
    {
        $base = null !== $in ? $in + 18 : ($out ? $out + 18 : ($aux ? $aux + 38 : null));
        if (null === $base) {
            return;
        }
        $this->put($base + 6 * $band, $values);
        $bypassOffset = null !== $in ? self::F + 6 + 2 * $ch : ($out ? self::F + 24 + 2 * ($ch - 8) : self::F + 22);
        $this->setBit($bypassOffset, $band, $bypass);
    }

    private function copy(int $source, int $target): void
    {
        foreach ([[Channel::isInput(...), Device::INPUT_BASE, 62, 0], [Channel::isOutput(...), Device::OUTPUT_BASE, 76, 8]] as [$is, $base, $size, $first]) {
            if ($is($source) && $is($target)) {
                $this->put($base + $size * ($target - $first), substr($this->config, $base + $size * ($source - $first), $size));
            }
        }
    }

    private function preset(Opcode $op, int $number, string $data): void
    {
        match ($op) {
            Opcode::StorePresetName => $this->pendingName = rtrim(substr($data, 0, 14)),
            Opcode::StorePreset => $this->presetNames[$number - 1] = $this->pendingName,
            default => null,
        };
    }

    /**
     * 17 × (lo, hi, peak) plus 4 trailing bytes. Routed inputs with gain produce a slowly moving level.
     */
    private function meters(): string
    {
        ++$this->tick;
        $data = '';
        for ($i = 0; $i < 17; ++$i) {
            $level = 0x05AD + (int) (0x2000 * (0.5 + 0.5 * sin($this->tick / 4 + $i)));
            $data .= pack('v', $level)."\x00";
        }

        return $data."\x00\x00\x00\x00";
    }
}
