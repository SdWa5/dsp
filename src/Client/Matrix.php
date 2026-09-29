<?php

namespace App\Client;

use App\Model\Device;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Opcode;
use App\Protocol\Scale;
use App\Transport\TransportInterface;

/**
 * High-level client for the t.racks 8x8 Matrix, independent of the transport.
 *
 *     $dsp = $factory->connect('192.168.68.10');   // or 'usb', 'demo'
 *     echo $dsp->info();
 *     $dsp->mute('out1', true);
 */
final class Matrix
{
    public const CONFIG_PAGES = 26;
    public const CONFIG_PAGE_SIZE = 50;
    /** Name slots 0x00–0x13 = U01–U20, 0x14 = F00 (factory default). */
    public const PRESET_SLOTS = 21;
    public const METER_CHANNELS = 17;
    /** User preset slots U01–U20 in the occupancy reply. */
    public const USER_PRESETS = 20;
    /**
     * A preset recall or store answers only once the device has read or written its flash, measured at 0.7 to
     * 1.3 s over USB (VIM3, 2026-09-28). Sending it again before that restarts the work.
     */
    public const PRESET_TIMEOUT = 3.0;
    /** Mute masks live on config page 0x18, bytes 14–15 (inputs) and 18–19 (outputs). */
    private const MUTE_PAGE = 0x18;
    private const MUTE_INPUT_OFFSET = 14;
    private const MUTE_OUTPUT_OFFSET = 18;

    /**
     * Retrying is harmless because every write command sets an absolute value.
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly float $timeout = 1.0,
        private readonly int $retries = 2,
    ) {
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Starts the session. Without the init the device does not answer over TCP.
     */
    public function handshake(): void
    {
        $this->request(Opcode::Init, reinit: false);
    }

    public function close(): void
    {
        $this->transport->close();
    }

    /**
     * Sends a command and waits for the matching reply. Foreign frames, e.g. replies to a vendor
     * editor running in parallel, are skipped without extending the deadline of an attempt.
     *
     * @param Opcode|int|null $reply expected reply command, the request command by default
     * @param string          $match required prefix of the reply data
     */
    public function request(Opcode|int $cmd, string $data = '', Opcode|int|null $reply = null, string $match = '', bool $reinit = true, ?float $timeout = null): Frame
    {
        $cmd = $cmd instanceof Opcode ? $cmd->value : $cmd;
        $reply = null === $reply ? $cmd : ($reply instanceof Opcode ? $reply->value : $reply);
        for ($attempt = 0; $attempt <= $this->retries; ++$attempt) {
            if ($attempt > 0 && $reinit) {
                // No reply: the session may have expired, so start a new one
                $this->sendInitQuietly();
            }
            $this->transport->send(Frame::request($cmd, $data));
            $frame = $this->await(static fn (Frame $f): bool => $f->cmd === $reply && str_starts_with($f->data, $match), $timeout ?? $this->timeout);
            if (null !== $frame) {
                return $frame;
            }
        }

        throw new DspException(\sprintf('No reply to command %02X.', $cmd));
    }

    /**
     * Sends a write command and waits for the ACK, longer for a preset recall or store.
     */
    public function write(Frame $frame): void
    {
        $slow = \in_array($frame->opcode(), [Opcode::LoadPreset, Opcode::StorePreset], true);
        $this->request($frame->cmd, $frame->data, Opcode::Ack, timeout: $slow ? max($this->timeout, self::PRESET_TIMEOUT) : null);
    }

    public function info(): string
    {
        return trim($this->request(Opcode::DeviceInfo)->data, "\x00 ");
    }

    /**
     * IP address of the network port. USB only, the device does not answer 0xB0 over TCP.
     */
    public function ipAddress(): string
    {
        $data = $this->request(Opcode::IpAddress)->data;
        if (\strlen($data) < 4) {
            throw new DspException('IP address reply too short.');
        }

        return implode('.', array_map('ord', str_split(substr($data, 0, 4))));
    }

    /**
     * Active preset as editor number (2 = U02). The name slot is number - 1.
     */
    public function activePreset(): int
    {
        return \ord($this->request(Opcode::ActivePreset)->data[0] ?? "\x00");
    }

    public function presetName(int $slot): string
    {
        $frame = $this->request(Opcode::PresetName, \chr($slot), match: \chr($slot));

        return rtrim(substr($frame->data, 1), " \x00");
    }

    /**
     * Which of U01..U20 hold a stored preset. The reply has one byte per slot, `FF` stored and `00` empty,
     * plus a trailing `00`. After a factory reset every slot is empty (checked on the device, 2026-09-28).
     *
     * @return list<bool>
     */
    public function presetOccupancy(): array
    {
        return self::parseOccupancy($this->request(Opcode::PresetOccupancy)->data);
    }

    /**
     * @return list<bool>
     */
    public static function parseOccupancy(string $data): array
    {
        return array_map(static fn (int $n): bool => "\x00" !== ($data[$n] ?? "\x00"), range(0, self::USER_PRESETS - 1));
    }

    /**
     * @return list<string> names of U01..U20 and F00
     */
    public function presetNames(): array
    {
        return array_map($this->presetName(...), range(0, self::PRESET_SLOTS - 1));
    }

    public function configPage(int $page): string
    {
        return substr($this->request(Opcode::ReadConfigPage, \chr($page), Opcode::ConfigPage, \chr($page))->data, 1);
    }

    /**
     * The complete live configuration, 26 pages.
     */
    public function config(): string
    {
        return implode('', array_map($this->configPage(...), range(0, self::CONFIG_PAGES - 1)));
    }

    public function device(): Device
    {
        $device = Device::parse($this->config());
        $device->setGroups($this->groupsRaw());

        return $device;
    }

    /**
     * Mute state of all channels, read from config page 0x18 or from a full configuration.
     *
     * @return array<string, bool> keyed in1..in8, out1..out8
     */
    public function muteState(?string $config = null): array
    {
        $page = $config ?? $this->configPage(self::MUTE_PAGE);
        $base = null === $config ? 0 : self::MUTE_PAGE * self::CONFIG_PAGE_SIZE;
        $inputs = unpack('v', $page, $base + self::MUTE_INPUT_OFFSET)[1] ?? 0;
        $outputs = unpack('v', $page, $base + self::MUTE_OUTPUT_OFFSET)[1] ?? 0;
        $state = [];
        for ($i = 0; $i < 8; ++$i) {
            $state['in'.($i + 1)] = (bool) ($inputs >> $i & 1);
        }
        for ($i = 0; $i < 8; ++$i) {
            $state['out'.($i + 1)] = (bool) ($outputs >> $i & 1);
        }

        return $state;
    }

    /**
     * Group control (0x6C 00): 8 × (gain %, mute, channel mask), 0–3 inputs, 4–7 outputs.
     */
    public function groupsRaw(): string
    {
        return $this->request(Opcode::Groups, "\x00")->data;
    }

    public function metersRaw(): string
    {
        return $this->request(Opcode::Meters)->data;
    }

    /**
     * Levels of In1–8, Out1–8 and Aux in dB, see Scale::meterDb().
     *
     * @return list<float>
     */
    public static function parseMeters(string $data): array
    {
        $levels = [];
        for ($i = 0; $i < self::METER_CHANNELS; ++$i) {
            $o = 3 * $i;
            $levels[] = \strlen($data) >= $o + 2 ? Scale::meterDb(\ord($data[$o]) | \ord($data[$o + 1]) << 8) : Scale::METER_FLOOR;
        }

        return $levels;
    }

    public function setGain(string|int $channel, float $db): void
    {
        $this->write(Commands::gainDb(Channel::parse($channel), $db));
    }

    public function mute(string|int $channel, bool $on = true): void
    {
        $this->write(Commands::mute(Channel::parse($channel), $on));
    }

    public function phase(string|int $channel, bool $inverted): void
    {
        $this->write(Commands::phase(Channel::parse($channel), $inverted));
    }

    /**
     * Output 1..8 gets exactly the inputs 1..8 in $inputs, an empty list mutes the route.
     *
     * @param list<int> $inputs
     */
    public function route(int $output, array $inputs): void
    {
        $mask = 0;
        foreach ($inputs as $input) {
            $mask |= 1 << Channel::input($input);
        }
        $this->write(Commands::routing(Channel::output($output), $mask));
    }

    private function sendInitQuietly(): void
    {
        $this->transport->send(Commands::init());
        $this->await(static fn (Frame $f): bool => Opcode::Init->value === $f->cmd, $this->timeout);
    }

    /**
     * @param callable(Frame): bool $matches
     */
    private function await(callable $matches, float $timeout): ?Frame
    {
        $deadline = hrtime(true) + (int) ($timeout * 1e9);
        while (($left = $deadline - hrtime(true)) > 0) {
            $frame = $this->transport->receive($left / 1e9);
            if (null === $frame) {
                return null;
            }
            if ($frame->isFromDsp() && $matches($frame)) {
                return $frame;
            }
        }

        return null;
    }
}
