<?php

namespace App\Model;

/**
 * One output block of the configuration (76 bytes).
 *
 * 0 name · 8 routing mask · 10 HPF slope · 12 HPF freq · 14 LPF slope · 16 LPF freq · 18 PEQ 7 × 6 ·
 * 60 ratio · 61 knee · 62 attack · 64 release · 66 threshold · 68 gain · 70 phase · 72 delay · 74 link
 */
final class OutputChannel
{
    public const SIZE = 76;
    public const BANDS = 7;

    /**
     * @param int           $route bit n = input n+1
     * @param list<PeqBand> $peq
     * @param int           $delay samples at 48 kHz
     */
    public function __construct(
        public string $name = '',
        public int $route = 0,
        public Filter $hpf = new Filter(),
        public Filter $lpf = new Filter(300, 0),
        public array $peq = [],
        public Compressor $comp = new Compressor(),
        public int $gain = 280,
        public bool $phase = false,
        public int $delay = 0,
        public int $link = 0,
        public bool $mute = false,
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
            route: Bytes::u16($bytes, $offset + 8),
            hpf: new Filter(Bytes::u16($bytes, $offset + 12), Bytes::u8($bytes, $offset + 10)),
            lpf: new Filter(Bytes::u16($bytes, $offset + 16), Bytes::u8($bytes, $offset + 14)),
            peq: array_map(static fn (int $i): PeqBand => PeqBand::parse($bytes, $offset + 18 + 6 * $i), range(0, self::BANDS - 1)),
            comp: Compressor::parse($bytes, $offset + 60),
            gain: Bytes::u16($bytes, $offset + 68),
            phase: 0 !== Bytes::u8($bytes, $offset + 70),
            delay: Bytes::u16($bytes, $offset + 72),
            link: Bytes::u8($bytes, $offset + 74),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'route' => $this->route,
            'hpf' => $this->hpf->toArray(),
            'lpf' => $this->lpf->toArray(),
            'peq' => array_map(static fn (PeqBand $b): array => $b->toArray(), $this->peq),
            'comp' => $this->comp->toArray(),
            'gain' => $this->gain,
            'phase' => $this->phase,
            'delay' => $this->delay,
            'link' => $this->link,
            'mute' => $this->mute,
            'eq_bypass' => $this->eqBypass,
        ];
    }
}
