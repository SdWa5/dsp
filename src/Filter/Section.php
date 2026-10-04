<?php

namespace App\Filter;

/**
 * One filter of a channel, described by its frequency response.
 */
interface Section
{
    /**
     * Complex gain at $hz as [real, imaginary].
     *
     * @return array{float, float}
     */
    public function at(float $hz): array;
}
