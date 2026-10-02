<?php

namespace App\Transport;

use App\Model\Device;
use App\Model\DeviceState;
use App\Protocol\Frame;
use App\Protocol\Opcode;
use App\Protocol\Scale;

/**
 * Simulated device for working without hardware. It answers like the 8x8, applies the write
 * commands with a known configuration offset to its own configuration and produces moving meters.
 */
final class DemoTransport implements TransportInterface
{
    /** @var list<Frame> */
    private array $queue = [];
    private DeviceState $state;
    /** @var array<int, string> preset number 0 (F00, the factory default) and 1..20 => stored configuration */
    private array $stored = [];
    private int $tick = 0;

    /**
     * @param string|null $factoryConfig the configuration a recall of F00 restores, without it F00 changes nothing
     */
    public function __construct(string $config, int $activePreset = 2, ?string $factoryConfig = null)
    {
        if (\strlen($config) < Device::CONFIG_SIZE) {
            throw new \LengthException('Demo configuration must be 1277 bytes.');
        }
        $names = array_fill(0, DeviceState::PRESET_SLOTS, '');
        $names[$activePreset - 1] = rtrim(substr($config, 2, 14), " \x00");
        $this->state = new DeviceState($config, null, $names, $activePreset, '192.168.68.10');
        $this->stored[$activePreset] = substr($config, 0, Device::CONFIG_SIZE);
        if (null !== $factoryConfig) {
            if (\strlen($factoryConfig) < Device::CONFIG_SIZE) {
                throw new \LengthException('Factory configuration must be 1277 bytes.');
            }
            $this->stored[0] = substr($factoryConfig, 0, Device::CONFIG_SIZE);
        }
    }

    public static function fromFile(string $path, ?string $factoryPath = null): self
    {
        $read = static function (string $file, string $what): string {
            $config = @file_get_contents($file);
            if (false === $config) {
                throw new TransportException(\sprintf('Cannot read %s %s.', $what, $file));
            }

            return $config;
        };

        return new self($read($path, 'demo configuration'), factoryConfig: null === $factoryPath ? null : $read($factoryPath, 'factory configuration'));
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
     * 17 × (lo, hi, x), the mask of limiting outputs and FF FF 00 as the device sends them. Every channel moves
     * slowly between -40 and -10 dB, and an output whose compressor works limits while its level reaches the
     * threshold.
     */
    private function meters(): string
    {
        ++$this->tick;
        $outputs = $this->state->toDevice()->outputs;
        $data = '';
        $limiting = 0;
        for ($i = 0; $i < 17; ++$i) {
            $db = -25 + 15 * sin($this->tick / 4 + $i);
            $data .= pack('v', Scale::dbToMeter($db))."\x00";
            $comp = $i >= 8 && $i < 16 ? $outputs[$i - 8]->comp : null;
            if (null !== $comp && $comp->works() && $db >= Scale::thresholdToDb($comp->threshold)) {
                $limiting |= 1 << ($i - 8);
            }
        }

        return $data.\chr($limiting)."\xFF\xFF\x00";
    }
}
