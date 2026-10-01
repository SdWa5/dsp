<?php

namespace App\Tests\Transport;

use App\Client\DspException;
use App\Model\Device;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Opcode;
use App\Protocol\Scale;
use App\Transport\DemoTransport;
use App\Transport\HidrawLocator;
use App\Transport\HidrawTransport;
use App\Transport\TcpTransport;
use App\Transport\TransportException;
use App\Transport\TransportFactory;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private const DEMO = __DIR__.'/../../resources/demo_config.bin';
    private const FACTORY = __DIR__.'/../../resources/factory_config.bin';

    private ?string $sysfs = null;

    protected function tearDown(): void
    {
        if (null !== $this->sysfs) {
            exec('rm -rf '.escapeshellarg($this->sysfs));
        }
    }

    public function testTcpStreamSplitAndGarbage(): void
    {
        [$server, $client] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP) ?: self::fail();
        $transport = TcpTransport::fromStream($client);
        $reply = Frame::reply(Opcode::DeviceInfo, '8x8Matrix V010')->encode();
        $ack = Frame::reply(Opcode::Ack)->encode();

        fwrite($server, "\x00\x10\xff".substr($reply, 0, 5));
        fwrite($server, substr($reply, 5).$ack);

        self::assertSame('8x8Matrix V010', $transport->receive(1)?->data);
        self::assertSame(Opcode::Ack->value, $transport->receive(1)?->cmd);
        self::assertNull($transport->receive(0.05));

        $transport->send(Commands::meters());
        self::assertSame(Commands::meters()->encode(), fread($server, 64));

        fclose($server);
        $this->expectException(TransportException::class);
        $transport->receive(1);
    }

    public function testTcpConnectFailure(): void
    {
        $this->expectException(TransportException::class);

        TcpTransport::connect('127.0.0.1', 1, 0.5);
    }

    public function testHidrawLocatorMatchesVendorAndProduct(): void
    {
        $this->sysfs = sys_get_temp_dir().'/trackdsp-sysfs-'.bin2hex(random_bytes(4));
        foreach (['hidraw0' => '0003:0000046D:0000C52B', 'hidraw10' => '0003:00000168:00000821', 'hidraw2' => '0003:00000168:00000821'] as $node => $id) {
            mkdir($this->sysfs.'/'.$node.'/device', 0o777, true);
            file_put_contents($this->sysfs.'/'.$node.'/device/uevent', "DRIVER=hid-generic\nHID_ID=$id\nHID_NAME=Musicrown Dsp Process\n");
        }

        $locator = new HidrawLocator($this->sysfs);

        self::assertSame(['/dev/hidraw2', '/dev/hidraw10'], $locator->find());
        self::assertSame('/dev/hidraw2', $locator->first());
    }

    public function testHidrawLocatorWithoutDevice(): void
    {
        $this->expectException(TransportException::class);

        (new HidrawLocator('/nonexistent'))->first();
    }

    public function testFactoryConnectsToDemo(): void
    {
        $dsp = (new TransportFactory(new HidrawLocator('/nonexistent'), self::DEMO))->connect('demo');

        self::assertSame('8x8Matrix V010', $dsp->info());
        self::assertSame('demo', $dsp->transport()->describe());
    }

    public function testFactoryWaitsBrieflyAndRetriesMoreOverUsb(): void
    {
        // A regular file stands in for a hidraw node that never answers
        $node = tempnam(sys_get_temp_dir(), 'trackdsp-hidraw-');
        self::assertIsString($node);
        $start = microtime(true);
        try {
            (new TransportFactory(new HidrawLocator('/nonexistent'), self::DEMO))->connect('usb:'.$node);
            self::fail('A silent device must not connect.');
        } catch (DspException) {
            $elapsed = microtime(true) - $start;
            $attempts = TransportFactory::USB_RETRIES + 1;
            self::assertSame($attempts * (HidrawTransport::REPORT_SIZE + 1), filesize($node));
            self::assertGreaterThanOrEqual($attempts * TransportFactory::USB_TIMEOUT * 0.9, $elapsed);
            self::assertLessThan($attempts * TransportFactory::USB_TIMEOUT + 0.5, $elapsed);
        } finally {
            @unlink($node);
        }
    }

    public function testFactoryRejectsInvalidTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TransportFactory(new HidrawLocator('/nonexistent'), self::DEMO))->open('host:port:x');
    }

    public function testDemoAppliesWrites(): void
    {
        $dsp = new \App\Client\Matrix(DemoTransport::fromFile(self::DEMO), 0.01);

        $dsp->setGain('out2', -6);
        $dsp->mute('in3');
        $dsp->mute('in1', false);
        $dsp->route(2, [5, 6]);
        $dsp->write(Commands::peq(0x0B, 1, Scale::dbToPeqGain(3), 150, 30, 1, true));
        $dsp->write(Commands::channelName(0, 'Vocal'));
        $dsp->write(Commands::highPass(8, 40, 5));
        $dsp->write(Commands::mute(\App\Protocol\Channel::AUX, true));
        $device = $dsp->device();

        self::assertSame(-6.0, Scale::gainToDb($device->outputs[1]->gain));
        self::assertTrue($device->inputs[2]->mute);
        self::assertFalse($device->inputs[0]->mute);
        self::assertSame(0b110000, $device->outputs[1]->route);
        self::assertSame(3.0, Scale::peqGainToDb($device->outputs[3]->peq[1]->gain));
        self::assertSame(150, $device->outputs[3]->peq[1]->freq);
        self::assertTrue($device->outputs[3]->peq[1]->bypass);
        self::assertSame('Vocal', $device->inputs[0]->name);
        self::assertSame([40, 5], [$device->outputs[0]->hpf->freq, $device->outputs[0]->hpf->slope]);
        self::assertTrue($device->aux->mute);
        self::assertSame(Device::CONFIG_SIZE, \strlen($device->raw));
        $levels = \App\Client\Matrix::parseMeters($dsp->metersRaw());
        self::assertCount(17, $levels);
        self::assertSame([], array_filter($levels, static fn (float $db): bool => $db < -40.05 || $db > -9.95), 'Demo meters move between -40 and -10 dB');
    }

    public function testDemoRecallsTheFactoryDefault(): void
    {
        $factory = substr((string) file_get_contents(self::FACTORY), 0, Device::CONFIG_SIZE);
        $dsp = (new TransportFactory(new HidrawLocator('/nonexistent'), self::DEMO, factoryConfigPath: self::FACTORY))->connect('demo');
        self::assertNotSame($factory, substr($dsp->config(), 0, Device::CONFIG_SIZE));

        $dsp->write(Commands::loadPreset(0));

        self::assertSame($factory, substr($dsp->config(), 0, Device::CONFIG_SIZE));
    }

    public function testDemoWithoutFactoryConfigKeepsItsSettingsOnF00(): void
    {
        $dsp = new \App\Client\Matrix(DemoTransport::fromFile(self::DEMO), 0.01);
        $before = $dsp->config();

        $dsp->write(Commands::loadPreset(0));

        self::assertSame($before, $dsp->config());
    }

    public function testDemoStoresPresetName(): void
    {
        $dsp = new \App\Client\Matrix(DemoTransport::fromFile(self::DEMO), 0.01);

        $dsp->write(Commands::presetName('Probe'));
        $dsp->write(Commands::storePreset(5));

        self::assertSame('Probe', $dsp->presetName(4));
        self::assertSame('Bulgaria26', $dsp->presetName(1));
    }
}
