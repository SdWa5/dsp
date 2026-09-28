<?php

namespace App\Model;

/**
 * Channel links of one side (inputs or outputs), as in the vendor editor and assets/lib/link.js: the master
 * holds the OR mask of its group, the other members hold 0, and a channel alone holds its own bit or 0.
 */
final readonly class Links
{
    /**
     * @param list<int> $masks link mask per channel 0..7 of the side
     */
    public function __construct(
        private array $masks,
    ) {
    }

    /**
     * The output links of a device state.
     *
     * @param array<mixed> $state
     */
    public static function outputs(array $state): self
    {
        $masks = [];
        foreach (\is_array($state['outputs'] ?? null) ? array_values($state['outputs']) : [] as $output) {
            $masks[] = \is_array($output) && \is_int($output['link'] ?? null) ? $output['link'] : 0;
        }

        return new self($masks);
    }

    /**
     * Channels 0..7 linked with channel $i, $i itself included, ascending.
     *
     * @return non-empty-list<int>
     */
    public function members(int $i): array
    {
        foreach ($this->masks as $mask) {
            if (($mask >> $i) & 1 && self::bits($mask) > 1) {
                $members = array_values(array_filter(range(0, 7), static fn (int $k): bool => (bool) (($mask >> $k) & 1)));
                if ([] !== $members) {
                    return $members;
                }
            }
        }

        return [$i];
    }

    public function isLinked(int $i): bool
    {
        return \count($this->members($i)) > 1;
    }

    /**
     * Group number 1..4 per channel 0..7 in the order of the masters, 0 for a channel alone.
     *
     * @return list<int>
     */
    public function groups(): array
    {
        $groups = array_fill(0, 8, 0);
        $group = 0;
        foreach ($this->masks as $mask) {
            if (self::bits($mask) > 1) {
                ++$group;
                for ($k = 0; $k < 8; ++$k) {
                    if (($mask >> $k) & 1) {
                        $groups[$k] = $group;
                    }
                }
            }
        }

        return $groups;
    }

    private static function bits(int $mask): int
    {
        return substr_count(decbin($mask & 0xFF), '1');
    }
}
