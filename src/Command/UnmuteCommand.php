<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('sdwa5-dsp:unmute|trackdsp:unmute', 'Unmute a channel')]
final class UnmuteCommand extends MuteCommand
{
    protected const MUTE = false;
}
