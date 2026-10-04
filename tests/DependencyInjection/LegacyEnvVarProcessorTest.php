<?php

namespace App\Tests\DependencyInjection;

use App\DependencyInjection\LegacyEnvVarProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

final class LegacyEnvVarProcessorTest extends TestCase
{
    public function testTheNewNameAppliesWithoutAnOldOne(): void
    {
        self::assertSame('demo', $this->read(['SDWA5_DSP_TARGET' => 'demo']));
    }

    public function testTheOldNameAppliesWithoutANewOne(): void
    {
        self::assertSame('usb', $this->read(['TRACKDSP_TARGET' => 'usb']));
    }

    public function testASetOldNameWinsOverTheNewDefault(): void
    {
        self::assertSame('usb', $this->read(['SDWA5_DSP_TARGET' => 'auto', 'TRACKDSP_TARGET' => 'usb']));
    }

    public function testAnEmptyOldNameLeavesTheNewOne(): void
    {
        self::assertSame('auto', $this->read(['SDWA5_DSP_TARGET' => 'auto', 'TRACKDSP_TARGET' => '']));
    }

    public function testNeitherNameIsMissingAsBefore(): void
    {
        $this->expectException(EnvNotFoundException::class);
        $this->read([]);
    }

    public function testTheContainerResolvesTheProcessor(): void
    {
        self::assertSame(['legacy' => 'string'], LegacyEnvVarProcessor::getProvidedTypes());
    }

    /**
     * @param array<string, string> $env
     */
    private function read(array $env): mixed
    {
        return (new LegacyEnvVarProcessor())->getEnv('legacy', 'SDWA5_DSP_TARGET', static fn (string $name) => $env[$name]
            ?? throw new EnvNotFoundException($name));
    }
}
