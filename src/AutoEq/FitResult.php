<?php

namespace App\AutoEq;

use App\Measurement\Response;

/**
 * Outcome of a PEQ fit: the bands and the deviation from the target before and after (predicted),
 * both on the grid of the correction range, positive where the system is too quiet.
 */
final readonly class FitResult
{
    /**
     * @param list<FittedBand> $bands
     */
    public function __construct(
        public array $bands,
        public Response $before,
        public Response $after,
    ) {
    }

    public static function rms(Response $deviation): float
    {
        return [] === $deviation->db ? 0.0 : sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $deviation->db)) / \count($deviation->db));
    }

    public static function peak(Response $deviation): float
    {
        return max([0.0, ...array_map(abs(...), $deviation->db)]);
    }
}
