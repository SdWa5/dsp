<?php

namespace App\Transport;

use App\Model\Device;
use App\Model\DeviceState;
use App\Protocol\Frame;
use App\Protocol\Opcode;

/**
 * Simulated device for working without hardware. It answers like the 8x8, applies the write
 * commands with a known configuration offset to its own configuration and produces moving meters.
 */
final class DemoTransport implements TransportInterface
{
    /** @var list<Frame> */
    private array $queue = [];
    private DeviceState $state;
    /** @var array<int, string> preset number 1..20 => stored configuration */
    private array $stored = [];
    private int $tick = 0;

    public function __construct(string $config, int $activePreset = 2)
    {
        if (\strlen($config) < Device::CONFIG_SIZE) {
            throw new \LengthException('Demo configuration must be 1277 bytes.');
        }
        $names = array_fill(0, DeviceState::PRESET_SLOTS, '');
        $names[$activePreset - 1] = rtrim(substr($config, 2, 14), " \x00");
        $this->state = new DeviceState($config, null, $names, $activePreset, '192.168.68.10');
        $this->stored[$activePreset] = substr($config, 0, Device::CONFIG_SIZE);
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
        return $this->state->config();
    }

    public function state(): DeviceState
    {
        return $this->state;
    }

    private function answer(Frame $frame): ?Frame
    {
        $slot = \ord($frame->data[0] ?? "\x00");
        $config = $this->state->config();

        switch ($frame->opcode()) {
            case Opcode::Init:
                return Frame::reply(Opcode::Init, "\x15");
            case Opcode::DeviceInfo:
                return Frame::reply(Opcode::DeviceInfo, '8x8Matrix V010');
            case Opcode::IpAddress:
                return Frame::reply(Opcode::IpAddress, "\xC0\xA8\x44\x0A");
            case Opcode::ActivePreset:
                return Frame::reply(Opcode::ActivePreset, \chr($this->state->activePreset ?? 0));
            case Opcode::PresetName:
                return Frame::reply(Opcode::PresetName, \chr($slot).str_pad($this->state->presetName($slot), 14));
            case Opcode::PresetOccupancy:
                $mask = '';
                for ($n = 1; $n <= 20; ++$n) {
                    $mask .= isset($this->stored[$n]) ? "\xFF" : "\x00";
                }

                return Frame::reply(Opcode::PresetOccupancy, $mask."\x00");
            case Opcode::ReadConfigPage:
                $page = substr(str_pad($config, 26 * 50, "\x00"), 50 * $slot, 50);

                return Frame::reply(Opcode::ConfigPage, \chr($slot).($slot < 25 ? $page : substr($page, 0, 27)));
            case Opcode::Groups:
                return Frame::reply(Opcode::Groups, $this->state->groups());
            case Opcode::Meters:
                return Frame::reply(Opcode::Meters, $this->meters());
            case Opcode::DeviceStatus:
                return Frame::reply(Opcode::DeviceStatus, str_repeat("\x00", 8));
            case null:
                return null;
            default:
                $this->write($frame, $slot);

                return Frame::reply(Opcode::Ack);
        }
    }

    /**
     * Applies a write. Stored presets keep a copy of the configuration, so a recall restores it.
     */
    private function write(Frame $frame, int $slot): void
    {
        $this->state->apply($frame);
        if (Opcode::StorePreset === $frame->opcode() && $slot >= 1 && $slot <= 20) {
            $name = str_pad(substr($this->state->presetName($slot - 1), 0, 14), 14);
            $this->stored[$slot] = substr_replace($this->state->config(), $name, 2, 14);
        } elseif (Opcode::LoadPreset === $frame->opcode() && isset($this->stored[$slot])) {
            $this->state->replaceConfig($this->stored[$slot]);
        }
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
