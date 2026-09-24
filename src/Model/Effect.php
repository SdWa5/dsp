<?php

namespace App\Model;

/**
 * Aux effect: echo (0x62), reverb (0x60), switch and gain (0x63).
 */
final class Effect
{
    public function __construct(
        public int $echoPredelay = 10,
        public int $echoDelay = 200,
        public int $echoRepeat = 60,
        public int $echo = 80,
        public int $reverbPredelay = 50,
        public int $reflect = 32,
        /** 139 = 1.96 s */
        public int $time = 139,
        public int $reverb = 30,
        public int $gain = 55,
        public ?bool $on = null,
    ) {
    }

    /**
     * Keys follow the JSON preset format of the Python editor.
     *
     * @return array<string, int|bool|null>
     */
    public function toArray(): array
    {
        return [
            'echo_predelay' => $this->echoPredelay,
            'echo_delay' => $this->echoDelay,
            'echo_repeat' => $this->echoRepeat,
            'echo' => $this->echo,
            'rev_predelay' => $this->reverbPredelay,
            'reflect' => $this->reflect,
            'time' => $this->time,
            'reverb' => $this->reverb,
            'gain' => $this->gain,
            'on' => $this->on,
        ];
    }
}
