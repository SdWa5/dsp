<?php

namespace App\Tests\Measurement;

use App\Measurement\Jit;
use PHPUnit\Framework\TestCase;

/**
 * Jit::enable() reports the truth: true only when the JIT runs afterwards, and never an error where it cannot.
 */
final class JitTest extends TestCase
{
    public function testEnableReportsWhetherTheJitRuns(): void
    {
        $enabled = Jit::enable();

        if (!$enabled) {
            self::assertNotSame(Jit::MODE, \function_exists('opcache_get_status') ? (opcache_get_status(false)['jit']['on'] ?? false) : false);

            return;
        }
        self::assertSame(Jit::MODE, \ini_get('opcache.jit'));
        $status = opcache_get_status(false);
        self::assertIsArray($status);
        self::assertTrue($status['jit']['on'] ?? false);
    }
}
