<?php

namespace App\Tests\Support;

/**
 * Minimal reader for USBPcap captures (pcap link type 249) of the vendor editor.
 *
 * Each packet starts with a USBPcap pseudo header: header length u16 · IRP id u64 · status u32 ·
 * function u16 · info u8 · bus u16 · device u16 · endpoint u8 · transfer u8 · data length u32.
 * Bit 7 of the endpoint is the direction (1 = IN, device to host).
 */
final class UsbPcapReader
{
    private const LINKTYPE_USBPCAP = 249;
    private const TRANSFER_INTERRUPT = 1;

    /**
     * HID reports of interrupt transfers that carry data.
     *
     * @return list<array{in: bool, data: string}>
     */
    public static function reports(string $path): array
    {
        $bytes = file_get_contents($path);
        if (false === $bytes || \strlen($bytes) < 24) {
            throw new \RuntimeException(\sprintf('Cannot read %s.', $path));
        }
        $header = unpack('Vmagic/vmajor/vminor/lzone/Vsigfigs/Vsnaplen/Vlinktype', $bytes);
        if (false === $header || 0xA1B2C3D4 !== $header['magic'] || self::LINKTYPE_USBPCAP !== $header['linktype']) {
            throw new \RuntimeException(\sprintf('%s is not a little-endian USBPcap capture.', $path));
        }

        $reports = [];
        $offset = 24;
        while ($offset + 16 <= \strlen($bytes)) {
            $record = unpack('Vsec/Vusec/Vincluded/Voriginal', $bytes, $offset);
            if (false === $record) {
                break;
            }
            $packet = substr($bytes, $offset + 16, $record['included']);
            $offset += 16 + $record['included'];
            if (\strlen($packet) < 27) {
                continue;
            }
            $usb = unpack('vheaderLength', $packet);
            $endpoint = \ord($packet[21]);
            $transfer = \ord($packet[22]);
            $data = substr($packet, $usb ? $usb['headerLength'] : 27);
            if (self::TRANSFER_INTERRUPT === $transfer && '' !== $data) {
                $reports[] = ['in' => 0 !== ($endpoint & 0x80), 'data' => $data];
            }
        }

        return $reports;
    }
}
