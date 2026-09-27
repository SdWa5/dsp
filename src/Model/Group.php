<?php

namespace App\Model;

/**
 * Sub control or group control entry.
 */
final class Group
{
    public function __construct(
        public int $gain = 100,
        public bool $mute = false,
        public int $mask = 0,
    ) {
    }

    /**
     * @param array{gain?: int, mute?: bool, mask?: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['gain'] ?? 100), (bool) ($data['mute'] ?? false), (int) ($data['mask'] ?? 0));
    }

    /**
     * @return array{gain: int, mute: bool, mask: int}
     */
    public function toArray(): array
    {
        return ['gain' => $this->gain, 'mute' => $this->mute, 'mask' => $this->mask];
    }
}
