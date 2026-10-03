<?php

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/** Startup readiness must not consume scripted status responses or insert requests into the log. */
final class ScriptedBridgeTest extends TestCase
{
    public function testReadyListenerKeepsTheFirstScriptedResponseAndEmptyLog(): void
    {
        $bridge = new ScriptedBridge([['queued' => 3], ['queued' => 0]]);
        try {
            self::assertSame([], $bridge->log());
            self::assertSame(3, $bridge->client()->request('status')['queued']);
            self::assertSame(0, $bridge->client()->request('status')['queued']);
            self::assertSame(['status', 'status'], $bridge->ops());
        } finally {
            $bridge->stop();
        }
    }
}
