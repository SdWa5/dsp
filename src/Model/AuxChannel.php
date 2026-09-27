<?php

namespace App\Model;

/**
 * The Aux block of the configuration (94 bytes).
 *
 * 0 name · 8 source mask · 10 effect on · 12 effect gain · 16 AutoMix slope · 18 AutoMix (att, rel, thr, mask, on) ·
 * 28 Camera (same) · 38 PEQ 5 × 6 · 68 echo (predelay, delay, repeat, echo) · 76 reverb (predelay, reflect,
 * time, reverb) · 84 ratio · 85 knee · 86 attack · 88 release · 90 threshold · 92 gain
 */
final class AuxChannel
{
    public const SIZE = 94;
    public const BANDS = 5;

    /**
     * @param int           $select source mask (command 3A 12)
     * @param list<PeqBand> $peq
     */
    public function __construct(
        public string $name = 'Aux',
        public int $select = 0,
        public Effect $effect = new Effect(),
        public MixFunction $automix = new MixFunction(),
        public ?int $automixSlope = null,
        public MixFunction $camera = new MixFunction(),
        public array $peq = [],
        public Compressor $comp = new Compressor(),
        public int $gain = 280,
        public ?bool $mute = null,
        public ?bool $eqBypass = null,
    ) {
        if ([] === $this->peq) {
            $this->peq = array_map(static fn (): PeqBand => new PeqBand(), range(1, self::BANDS));
        }
    }

    public static function parse(string $bytes, int $offset): self
    {
        $u16 = static fn (int $at): int => Bytes::u16($bytes, $offset + $at);

        return new self(
            name: Bytes::name($bytes, $offset),
            select: $u16(8),
            effect: new Effect($u16(68), $u16(70), $u16(72), $u16(74), $u16(76), $u16(78), $u16(80), $u16(82),
                gain: Bytes::u8($bytes, $offset + 12), on: 0 !== Bytes::u8($bytes, $offset + 10)),
            automix: MixFunction::parse($bytes, $offset + 18),
            automixSlope: Bytes::u8($bytes, $offset + 16),
            camera: MixFunction::parse($bytes, $offset + 28),
            peq: array_map(static fn (int $i): PeqBand => PeqBand::parse($bytes, $offset + 38 + 6 * $i), range(0, self::BANDS - 1)),
            comp: Compressor::parse($bytes, $offset + 84),
            gain: $u16(92),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'select' => $this->select,
            'effect' => $this->effect->toArray(),
            'automix' => $this->automix->toArray(),
            'automix_slope' => $this->automixSlope,
            'camera' => $this->camera->toArray(),
            'peq' => array_map(static fn (PeqBand $b): array => $b->toArray(), $this->peq),
            'comp' => $this->comp->toArray(),
            'gain' => $this->gain,
            'mute' => $this->mute,
            'eq_bypass' => $this->eqBypass,
        ];
    }
}
