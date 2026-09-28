<?php

namespace App\Tests\Support;

use App\Protocol\Frame;
use App\Protocol\Opcode;
use App\Transport\TransportInterface;

/**
 * Answers like the real 8x8 and mixes in foreign meter replies, as happens with a vendor editor
 * polling in parallel. $silent drops all answers, $chatty returns foreign frames endlessly.
 */
final class FakeDsp implements TransportInterface
{
    /** @var list<Frame> */
    public array $sent = [];
    /** @var list<float> the wait of every receive() */
    public array $timeouts = [];
    /** @var list<Frame> */
    private array $queue = [];

    public function __construct(private readonly bool $silent = false, private readonly bool $chatty = false)
    {
    }

    public function send(Frame $frame): void
    {
        $this->sent[] = $frame;
        if ($this->silent) {
            return;
        }
        $this->queue[] = Frame::reply(Opcode::Meters, str_repeat("\x00", 55));
        $reply = match ($frame->opcode()) {
            Opcode::DeviceInfo => Frame::reply(Opcode::DeviceInfo, '8x8Matrix V010'),
            Opcode::IpAddress => Frame::reply(Opcode::IpAddress, "\xC0\xA8\x44\x0A"),
            Opcode::ActivePreset => Frame::reply(Opcode::ActivePreset, "\x02"),
            Opcode::PresetName => Frame::reply(Opcode::PresetName, $frame->data[0].'Bulgaria26    '),
            Opcode::ReadConfigPage => Frame::reply(Opcode::ConfigPage, $frame->data[0].str_repeat($frame->data[0], 50)),
            Opcode::PresetOccupancy => Frame::reply(Opcode::PresetOccupancy, str_repeat("\x00", 19)."\xFF\x00"),
            Opcode::Gain, Opcode::Mute, Opcode::Phase, Opcode::Routing, Opcode::LoadPreset, Opcode::StorePreset => Frame::reply(Opcode::Ack),
            default => null,
        };
        if (null !== $reply) {
            $this->queue[] = $reply;
        }
    }

    public function receive(float $timeout): ?Frame
    {
        $this->timeouts[] = $timeout;
        if ($this->chatty) {
            return Frame::reply(Opcode::Meters, str_repeat("\x00", 55));
        }

        return array_shift($this->queue);
    }

    public function close(): void
    {
    }

    public function describe(): string
    {
        return 'fake';
    }
}
