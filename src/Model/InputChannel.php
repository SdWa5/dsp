<?php

namespace App\Model;

/**
 * One input block of the configuration (62 bytes).
 *
 * 0 name (8) · 8 unknown, probably +48 V · 9 Line/Mic · 10 FBQ · 12 gate attack · 14 gate release ·
 * 16 gate threshold · 18 PEQ 5 × (gain u16, freq u16, q, type) · 48 HPF slope · 50 HPF freq ·
 * 52 LPF slope · 54 LPF freq · 56 gain · 58 phase · 60 link
 */
final class InputChannel
{
    public const SIZE = 62;
    public const BANDS = 5;

    /**
     * @param list<PeqBand> $peq
     * @param ?int          $mode    0 Line, 1..3 Mic
     * @param ?bool         $phantom not located in the configuration yet, remembered when set
     */
    public function __construct(
        public string $name = '',
        public Gate $gate = new Gate(),
        public array $peq = [],
        public Filter $hpf = new Filter(),
        public Filter $lpf = new Filter(300, 0),
        public int $gain = 280,
        public bool $phase = false,
        public int $link = 0,
        public bool $mute = false,
        public ?int $mode = null,
        public ?bool $phantom = null,
        public ?int $fbq = null,
        public ?bool $eqBypass = null,
    ) {
        if ([] === $this->peq) {
            $this->peq = array_map(static fn (): PeqBand => new PeqBand(), range(1, self::BANDS));
        }
    }

    public static function parse(string $bytes, int $offset): self
    {
        return new self(
            name: Bytes::name($bytes, $offset),
            gate: new Gate(Bytes::u16($bytes, $offset + 12), Bytes::u16($bytes, $offset + 14), Bytes::u16($bytes, $offset + 16)),
            peq: array_map(static fn (int $i): PeqBand => PeqBand::parse($bytes, $offset + 18 + 6 * $i), range(0, self::BANDS - 1)),
            hpf: new Filter(Bytes::u16($bytes, $offset + 50), Bytes::u8($bytes, $offset + 48)),
            lpf: new Filter(Bytes::u16($bytes, $offset + 54), Bytes::u8($bytes, $offset + 52)),
            gain: Bytes::u16($bytes, $offset + 56),
            phase: 0 !== Bytes::u8($bytes, $offset + 58),
            link: Bytes::u8($bytes, $offset + 60),
            mode: Bytes::u8($bytes, $offset + 9),
            fbq: Bytes::u8($bytes, $offset + 10),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'gate' => $this->gate->toArray(),
            'peq' => array_map(static fn (PeqBand $b): array => $b->toArray(), $this->peq),
            'hpf' => $this->hpf->toArray(),
            'lpf' => $this->lpf->toArray(),
            'gain' => $this->gain,
            'phase' => $this->phase,
            'link' => $this->link,
            'mute' => $this->mute,
            'mode' => $this->mode,
            'phantom' => $this->phantom,
            'fbq' => $this->fbq,
            'eq_bypass' => $this->eqBypass,
        ];
    }
}
