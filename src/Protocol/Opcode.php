<?php

namespace App\Protocol;

/**
 * Command bytes of the USB-HID/TCP protocol.
 *
 * Status markers follow docs/protocol.md: "confirmed" means captured on the 8x8,
 * "4x4" means taken from the sister device DSP 4x4 and not yet captured on the 8x8.
 */
enum Opcode: int
{
    case Ack = 0x01;
    case Init = 0x10;
    /** Sent by the vendor editor before the init, purpose unknown. */
    case PreInit = 0x11;
    case Activate = 0x12;
    case DeviceInfo = 0x13;
    case ActivePreset = 0x14;
    case DelayUnit = 0x15;
    /** 4x4. */
    case LoadPreset = 0x20;
    /** 4x4. */
    case StorePreset = 0x21;
    /** 4x4. */
    case PresetOccupancy = 0x22;
    case ConfigPage = 0x24;
    /** 4x4. */
    case StorePresetName = 0x26;
    case ReadConfigPage = 0x27;
    case PresetName = 0x29;
    case CopyChannel = 0x2A;
    /** 4x4, byte 6 of the reply is the lock flag. */
    case DeviceStatus = 0x2C;
    /** 4x4. */
    case Unlock = 0x2D;
    /** 4x4, locks the device immediately. */
    case SetPin = 0x2F;
    case Compressor = 0x30;
    case LowPass = 0x31;
    case HighPass = 0x32;
    case Peq = 0x33;
    case Gain = 0x34;
    case Mute = 0x35;
    case Phase = 0x36;
    case Delay = 0x38;
    case TestTone = 0x39;
    case Routing = 0x3A;
    case Link = 0x3B;
    case EqBypass = 0x3C;
    case ChannelName = 0x3D;
    case Gate = 0x3E;
    case Meters = 0x40;
    case InputMode = 0x55;
    case Phantom = 0x56;
    case AutoMixSlope = 0x5A;
    case SubControl = 0x5B;
    case Reverb = 0x60;
    case Fbq = 0x61;
    case Echo = 0x62;
    case Effect = 0x63;
    case MixFunction = 0x64;
    case WorkingStatus = 0x6A;
    case Group = 0x6B;
    case Groups = 0x6C;
    /** Answered over USB only. */
    case IpAddress = 0xB0;
    case DeviceId = 0xBB;
}
