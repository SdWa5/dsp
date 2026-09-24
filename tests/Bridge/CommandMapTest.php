<?php

namespace App\Tests\Bridge;

use App\Bridge\CommandMap;
use App\Protocol\Commands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandMapTest extends TestCase
{
    public function testBuildsWithNamedArguments(): void
    {
        self::assertSame(Commands::mute(8, true)->encode(), CommandMap::build('mute', ['channel' => 8, 'on' => true])->encode());
        self::assertSame(
            Commands::peq(0x0B, 1, 150, 120, 30, 1, false)->encode(),
            CommandMap::build('peq', ['channel' => 11, 'band' => 1, 'gainRaw' => 150, 'freqIndex' => 120, 'qIndex' => 30, 'type' => 1, 'bypass' => false])->encode(),
        );
    }

    public function testCastsFormValuesAndUsesDefaults(): void
    {
        self::assertSame(Commands::gainDb(0, -6.5)->encode(), CommandMap::build('gainDb', ['channel' => '0', 'db' => '-6.5'])->encode());
        self::assertSame(Commands::testTone(1)->encode(), CommandMap::build('testTone', ['mode' => 1])->encode());
        self::assertSame(Commands::phantom(2, true)->encode(), CommandMap::build('phantom', ['channel' => 2, 'on' => 1])->encode());
        self::assertSame(Commands::gain(8, 280)->encode(), CommandMap::build('gain', ['channel' => 8, 'raw' => 280])->encode());
        self::assertSame(Commands::echo(1, 300, 2, 1000)->encode(), CommandMap::build('echo', ['predelay' => 1, 'delay' => 300, 'repeat' => 2, 'level' => 1000])->encode());
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('invalid')]
    public function testRejects(string $command, array $args, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        CommandMap::build($command, $args);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'read command' => ['readConfigPage', ['page' => 0], 'Unknown command'];
        yield 'PIN' => ['setPin', ['pin' => '1234'], 'Unknown command'];
        yield 'missing' => ['mute', ['channel' => 8], 'needs the argument "on"'];
        yield 'extra' => ['mute', ['channel' => 8, 'on' => true, 'x' => 1], 'no argument "x"'];
        yield 'type' => ['mute', ['channel' => 'out1', 'on' => true], 'must be of type int'];
        yield 'bool' => ['mute', ['channel' => 8, 'on' => 'yes'], 'must be of type bool'];
        yield 'channel' => ['mute', ['channel' => 16, 'on' => true], 'not a channel index'];
        yield 'negative' => ['delay', ['channel' => 8, 'samples' => -1], 'must be 0..65535'];
        yield 'u16' => ['delay', ['channel' => 8, 'samples' => 70000], 'must be 0..65535'];
        yield 'byte' => ['fbq', ['channel' => 0, 'level' => 256], '"level" must be 0..255'];
        yield 'mask byte' => ['group', ['index' => 0, 'gainPercent' => 50, 'muted' => false, 'mask' => 0x100], '"mask" must be 0..255'];
        yield 'range' => ['gain', ['channel' => 8, 'raw' => 401], 'Gain raw value'];
        yield 'F00' => ['storePreset', ['number' => 0], 'Only U01..U20'];
    }

    public function testEveryListedCommandExists(): void
    {
        foreach (CommandMap::COMMANDS as $command) {
            self::assertTrue(method_exists(Commands::class, $command), $command);
        }
    }
}
