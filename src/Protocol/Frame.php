<?php

namespace App\Protocol;

/**
 * One protocol frame: 10 02 | src | dst | len | cmd data… | 10 03 | xor.
 *
 * len counts cmd plus data, xor is len ^ cmd ^ data…, there is no byte stuffing.
 * Over USB the frame sits at the start of a 64-byte HID report, over TCP it is sent as is.
 */
final readonly class Frame
{
    public const STX = "\x10\x02";
    public const ETX = "\x10\x03";
    public const ADDRESS_PC = 0x00;
    public const ADDRESS_DSP = 0x01;
    /** Bytes around the body: STX, src, dst, len before it and ETX, xor after it. */
    public const OVERHEAD = 8;

    public function __construct(
        public int $src,
        public int $dst,
        public int $cmd,
        public string $data = '',
    ) {
    }

    /**
     * A frame from the PC to the DSP.
     */
    public static function request(Opcode|int $cmd, string $data = ''): self
    {
        return new self(self::ADDRESS_PC, self::ADDRESS_DSP, $cmd instanceof Opcode ? $cmd->value : $cmd, $data);
    }

    /**
     * A frame from the DSP to the PC, as the device or a simulation sends it.
     */
    public static function reply(Opcode|int $cmd, string $data = ''): self
    {
        return new self(self::ADDRESS_DSP, self::ADDRESS_PC, $cmd instanceof Opcode ? $cmd->value : $cmd, $data);
    }

    public function encode(): string
    {
        $body = \chr($this->cmd).$this->data;
        $length = \strlen($body);
        if ($length > 255) {
            throw new \LengthException(\sprintf('Frame body too long (%d bytes).', $length));
        }

        return self::STX.\chr($this->src).\chr($this->dst).\chr($length).$body.self::ETX.\chr(self::checksum($body));
    }

    /**
     * Parses a frame at the start of $bytes. Trailing bytes such as HID report padding are ignored.
     */
    public static function decode(string $bytes): ?self
    {
        if (\strlen($bytes) < self::OVERHEAD + 1 || !str_starts_with($bytes, self::STX)) {
            return null;
        }
        $length = \ord($bytes[4]);
        $body = substr($bytes, 5, $length);
        $tail = substr($bytes, 5 + $length, 3);
        if (0 === $length || \strlen($body) !== $length || 3 !== \strlen($tail)
            || !str_starts_with($tail, self::ETX) || \ord($tail[2]) !== self::checksum($body)) {
            return null;
        }

        return new self(\ord($bytes[2]), \ord($bytes[3]), \ord($body[0]), substr($body, 1));
    }

    public static function checksum(string $body): int
    {
        $xor = \strlen($body);
        foreach (str_split($body) as $byte) {
            $xor ^= \ord($byte);
        }

        return $xor;
    }

    public function opcode(): ?Opcode
    {
        return Opcode::tryFrom($this->cmd);
    }

    public function isFromDsp(): bool
    {
        return self::ADDRESS_DSP === $this->src;
    }

    /**
     * Lower-case hex with spaces, the notation used in captures and tests ("10 02 00 01 …").
     */
    public function hex(): string
    {
        return implode(' ', str_split(bin2hex($this->encode()), 2));
    }

    /**
     * Command and data only ("35 08 01"), the notation of captures/results.jsonl.
     */
    public function shortHex(): string
    {
        return implode(' ', str_split(bin2hex(\chr($this->cmd).$this->data), 2));
    }
}
