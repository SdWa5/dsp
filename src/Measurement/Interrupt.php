<?php

namespace App\Measurement;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A flag a command sets from its signal handler, so that Playback stops before the recording and puts the
 * device back instead of the process ending with the tone on.
 */
#[Exclude]
final class Interrupt
{
    private bool $triggered = false;

    public function trigger(): void
    {
        $this->triggered = true;
    }

    public function triggered(): bool
    {
        return $this->triggered;
    }
}
