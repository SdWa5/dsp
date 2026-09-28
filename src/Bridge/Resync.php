<?php

namespace App\Bridge;

use App\Client\Matrix;
use App\Model\DeviceState;
use App\Protocol\Frame;

/**
 * A full re-read of the device, one request per step, so the bridge serves its clients between the steps.
 *
 * The steps are the 26 configuration pages, the group control block, the 21 preset names, the active
 * preset and the preset occupancy, about 70 ms each over USB. Writes that reach the device while the re-read runs are recorded and
 * applied again on top of the result, so a page read before a write does not undo it. Every write sets an
 * absolute value, so applying one again is harmless. A preset recall or store changes the whole
 * configuration and needs a new re-read instead.
 */
final class Resync
{
    public const STEPS = Matrix::CONFIG_PAGES + 1 + Matrix::PRESET_SLOTS + 2;

    private int $step = 0;
    /** @var list<string> */
    private array $pages = [];
    private string $groups = '';
    /** @var list<string> */
    private array $names = [];
    private int $active = 0;
    /** @var list<bool> */
    private array $stored = [];
    /** @var list<Frame> */
    private array $written = [];

    /**
     * Sends the next request. Returns true once every step is done.
     */
    public function step(Matrix $dsp): bool
    {
        $step = $this->step;
        $names = Matrix::CONFIG_PAGES + 1;
        match (true) {
            $step < Matrix::CONFIG_PAGES => $this->pages[] = $dsp->configPage($step),
            Matrix::CONFIG_PAGES === $step => $this->groups = $dsp->groupsRaw(),
            $step < $names + Matrix::PRESET_SLOTS => $this->names[] = $dsp->presetName($step - $names),
            $step === $names + Matrix::PRESET_SLOTS => $this->active = $dsp->activePreset(),
            default => $this->stored = $dsp->presetOccupancy(),
        };
        ++$this->step;

        return $this->done();
    }

    public function done(): bool
    {
        return $this->step >= self::STEPS;
    }

    public function progress(): float
    {
        return min(1.0, $this->step / self::STEPS);
    }

    /**
     * Records a write that went to the device while the re-read runs.
     */
    public function written(Frame $frame): void
    {
        $this->written[] = $frame;
    }

    /**
     * Puts the result into $state and applies the recorded writes again, or builds a new state.
     */
    public function applyTo(?DeviceState $state, string $ip = ''): DeviceState
    {
        if (!$this->done()) {
            throw new \LogicException('The re-read is not finished.');
        }
        $config = implode('', $this->pages);
        if (null === $state) {
            $state = new DeviceState($config, $this->groups, $this->names, $this->active, $ip);
        } else {
            $state->replaceConfig($config, $this->groups);
            $state->replacePresetNames($this->names);
            $state->activePreset = $this->active;
        }
        $state->presetStored = $this->stored;
        foreach ($this->written as $frame) {
            $state->apply($frame);
        }

        return $state;
    }
}
