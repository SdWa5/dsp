<?php

namespace App\Measurement;

/**
 * Switches PHP's JIT on for the rest of the process, for the commands that run FFTs.
 *
 * The JIT makes the FFT about twice as fast, but switched on for every process it costs each start about
 * 0.4 s of compiling Symfony's boot code, which the test suite pays for every bridge subprocess. So the JIT stays
 * "off" with its buffer reserved (`.ddev/php/jit.ini`), and a command switches it on before it touches the
 * measurement classes, since the JIT only takes code compiled after the switch. Where the JIT is disabled or
 * has no buffer, nothing changes.
 */
final class Jit
{
    /** Function JIT for hot functions. The tracing JIT gains little on the FFT loops */
    public const MODE = '1235';

    public static function enable(): bool
    {
        if (!\function_exists('opcache_get_status') || false === @ini_set('opcache.jit', self::MODE)) {
            return false;
        }
        $status = @opcache_get_status(false);

        return \is_array($status) && \is_array($status['jit'] ?? null) && true === ($status['jit']['on'] ?? false);
    }
}
