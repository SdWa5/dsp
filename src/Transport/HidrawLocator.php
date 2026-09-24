<?php

namespace App\Transport;

/**
 * Finds the hidraw nodes of connected devices (USB VID 0x0168, PID 0x0821, "Musicrown / Dsp Process").
 */
final readonly class HidrawLocator
{
    public const VENDOR_ID = 0x0168;
    public const PRODUCT_ID = 0x0821;

    public function __construct(
        private string $sysfsRoot = '/sys/class/hidraw',
        private string $devRoot = '/dev',
    ) {
    }

    /**
     * @return list<string> device paths such as /dev/hidraw3, sorted by name
     */
    public function find(): array
    {
        $wanted = \sprintf('HID_ID=%04X:%08X:%08X', 0x0003, self::VENDOR_ID, self::PRODUCT_ID);
        $paths = [];
        foreach (glob($this->sysfsRoot.'/hidraw*') ?: [] as $node) {
            $uevent = @file_get_contents($node.'/device/uevent');
            if (false !== $uevent && \in_array($wanted, array_map('strtoupper', preg_split('/\R/', $uevent) ?: []), true)) {
                $paths[] = $this->devRoot.'/'.basename($node);
            }
        }
        natsort($paths);

        return array_values($paths);
    }

    public function first(): string
    {
        return $this->find()[0] ?? throw new TransportException(\sprintf('No DSP found (USB %04X:%04X). Check the USB cable and the power supply.', self::VENDOR_ID, self::PRODUCT_ID));
    }
}
