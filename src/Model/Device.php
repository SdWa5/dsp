<?php

namespace App\Model;

use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Frame;

/**
 * Device state: the 1277-byte configuration read with command 0x27, plus values the
 * configuration does not carry and that are remembered once set (+48 V, working status …).
 *
 * Layout: 0 header (FF FF + preset name, 14) · 16 8 × input (62) · 512 Aux (94) · 606 8 × output (76) ·
 * 1214 footer (63): 0 mute inputs · 2 mute Aux · 4 mute outputs · 6+2k band bypass input k ·
 * 22 band bypass Aux · 24+2k band bypass output k · 40 sub in (gain %, mute, mask, x) · 44 sub out ·
 * 48 test tone mode · 52 delay unit · 56 EQ bypass inputs · 58 EQ bypass outputs · 60 EQ bypass Aux
 */
final class Device
{
    public const CONFIG_SIZE = 1277;
    public const INPUT_BASE = 16;
    public const AUX_BASE = 512;
    public const OUTPUT_BASE = 606;
    public const FOOTER_BASE = 1214;
    public const FILE_FORMAT = 'trackdsp-8x8';
    public const FILE_VERSION = 1;

    /** @var list<InputChannel> */
    public array $inputs;
    /** @var list<OutputChannel> */
    public array $outputs;
    public AuxChannel $aux;
    /** @var list<int> */
    public array $footer = [];
    /** @var list<Group> [inputs, outputs] */
    public array $sub;
    /** @var list<Group> 0–3 inputs, 4–7 outputs */
    public array $groups;
    /** @var list<int> */
    public array $subExtra = [0, 0];
    public ?int $toneMode = null;
    public int $toneFreq = 17;
    public int $delayUnit = 0;
    public ?int $workingStatus = null;
    public int $deviceId = 1;
    public string $ip = '';
    public ?int $activePreset = null;
    /** @var list<string> */
    public array $presetNames = [];
    /** Raw configuration as read from the device, empty for a default state. */
    public string $raw = '';

    public function __construct(public string $presetName = '')
    {
        $this->inputs = array_map(static fn (int $i): InputChannel => new InputChannel('In'.$i), range(1, Channel::INPUTS));
        $this->outputs = array_map(static fn (int $i): OutputChannel => new OutputChannel('Out'.$i), range(1, Channel::OUTPUTS));
        $this->aux = new AuxChannel();
        $this->sub = [new Group(), new Group()];
        $this->groups = array_map(static fn (): Group => new Group(), range(1, 8));
    }

    public static function parse(string $config): self
    {
        if (\strlen($config) < self::CONFIG_SIZE) {
            throw new \LengthException(\sprintf('Configuration too short: %d instead of %d bytes.', \strlen($config), self::CONFIG_SIZE));
        }
        $b = substr($config, 0, self::CONFIG_SIZE);
        $d = new self(rtrim(substr($b, 2, 14), " \x00"));
        $d->raw = $b;
        $d->inputs = array_map(static fn (int $i): InputChannel => InputChannel::parse($b, self::INPUT_BASE + InputChannel::SIZE * $i), range(0, 7));
        $d->outputs = array_map(static fn (int $i): OutputChannel => OutputChannel::parse($b, self::OUTPUT_BASE + OutputChannel::SIZE * $i), range(0, 7));
        $d->aux = AuxChannel::parse($b, self::AUX_BASE);
        $d->footer = array_values(unpack('C*', substr($b, self::FOOTER_BASE)) ?: []);

        $f = self::FOOTER_BASE;
        $inMute = Bytes::u16($b, $f);
        $outMute = Bytes::u16($b, $f + 4);
        $inEqBypass = Bytes::u16($b, $f + 56);
        $outEqBypass = Bytes::u16($b, $f + 58);
        for ($i = 0; $i < 8; ++$i) {
            $d->inputs[$i]->mute = (bool) ($inMute >> $i & 1);
            $d->outputs[$i]->mute = (bool) ($outMute >> $i & 1);
            $d->inputs[$i]->eqBypass = (bool) ($inEqBypass >> $i & 1);
            $d->outputs[$i]->eqBypass = (bool) ($outEqBypass >> $i & 1);
            self::applyBandBypass($d->inputs[$i]->peq, Bytes::u16($b, $f + 6 + 2 * $i));
            self::applyBandBypass($d->outputs[$i]->peq, Bytes::u16($b, $f + 24 + 2 * $i));
        }
        self::applyBandBypass($d->aux->peq, Bytes::u16($b, $f + 22));
        $d->aux->mute = (bool) (Bytes::u8($b, $f + 2) & 1);
        $d->aux->eqBypass = (bool) (Bytes::u8($b, $f + 60) & 1);
        $d->sub = [
            new Group(Bytes::u8($b, $f + 40), 0 !== Bytes::u8($b, $f + 41), Bytes::u8($b, $f + 42)),
            new Group(Bytes::u8($b, $f + 44), 0 !== Bytes::u8($b, $f + 45), Bytes::u8($b, $f + 46)),
        ];
        $d->subExtra = [Bytes::u8($b, $f + 43), Bytes::u8($b, $f + 47)];
        $tone = Bytes::u8($b, $f + 48);
        $d->toneMode = $tone < 4 ? $tone : null;
        $unit = Bytes::u8($b, $f + 52);
        $d->delayUnit = $unit < 3 ? $unit : 0;

        return $d;
    }

    /**
     * Takes over the reply to 0x6C: 8 × (gain %, mute, mask).
     */
    public function setGroups(string $raw): void
    {
        $groups = [];
        for ($i = 0; $i < min(8, intdiv(\strlen($raw), 3)); ++$i) {
            $groups[] = new Group(\ord($raw[3 * $i]), 0 !== \ord($raw[3 * $i + 1]), \ord($raw[3 * $i + 2]));
        }
        $this->groups = $groups;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preset_name' => $this->presetName,
            'inputs' => array_map(static fn (InputChannel $c): array => $c->toArray(), $this->inputs),
            'outputs' => array_map(static fn (OutputChannel $c): array => $c->toArray(), $this->outputs),
            'aux' => $this->aux->toArray(),
            'footer' => $this->footer,
            'sub' => array_map(static fn (Group $g): array => $g->toArray(), $this->sub),
            'groups' => array_map(static fn (Group $g): array => $g->toArray(), $this->groups),
            'sub_extra' => $this->subExtra,
            'tone_mode' => $this->toneMode,
            'tone_freq' => $this->toneFreq,
            'delay_unit' => $this->delayUnit,
            'working_status' => $this->workingStatus,
            'device_id' => $this->deviceId,
            'ip' => $this->ip,
            'active_preset' => $this->activePreset,
            'preset_names' => $this->presetNames,
            'raw' => bin2hex($this->raw),
        ];
    }

    /**
     * Preset file, compatible with the files of the Python editor.
     */
    public function toJson(): string
    {
        return json_encode(
            ['format' => self::FILE_FORMAT, 'version' => self::FILE_VERSION, 'device' => $this->toArray()],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Restores the configuration from the raw hex and the remembered values that are not part of it.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || self::FILE_FORMAT !== ($data['format'] ?? null) || !\is_array($data['device'] ?? null)) {
            throw new \InvalidArgumentException('Not a trackdsp-8x8 preset file.');
        }
        $dd = $data['device'];
        $raw = hex2bin((string) ($dd['raw'] ?? '')) ?: '';
        $d = \strlen($raw) >= self::CONFIG_SIZE ? self::parse($raw) : new self();

        foreach (array_values((array) ($dd['inputs'] ?? [])) as $i => $in) {
            if (!isset($d->inputs[$i]) || !\is_array($in)) {
                continue;
            }
            $d->inputs[$i]->mode = isset($in['mode']) ? (int) $in['mode'] : null;
            $d->inputs[$i]->phantom = isset($in['phantom']) ? (bool) $in['phantom'] : null;
            $d->inputs[$i]->fbq = isset($in['fbq']) ? (int) $in['fbq'] : null;
            $d->inputs[$i]->eqBypass = isset($in['eq_bypass']) ? (bool) $in['eq_bypass'] : null;
            self::restoreBandBypass($d->inputs[$i]->peq, $in['peq'] ?? []);
        }
        foreach (array_values((array) ($dd['outputs'] ?? [])) as $i => $out) {
            if (!isset($d->outputs[$i]) || !\is_array($out)) {
                continue;
            }
            $d->outputs[$i]->eqBypass = isset($out['eq_bypass']) ? (bool) $out['eq_bypass'] : null;
            self::restoreBandBypass($d->outputs[$i]->peq, $out['peq'] ?? []);
        }
        if (\array_key_exists('tone_mode', $dd)) {
            $d->toneMode = null === $dd['tone_mode'] ? null : (int) $dd['tone_mode'];
        }
        $d->toneFreq = (int) ($dd['tone_freq'] ?? $d->toneFreq);
        $d->delayUnit = (int) ($dd['delay_unit'] ?? $d->delayUnit);
        if (\array_key_exists('working_status', $dd)) {
            $d->workingStatus = null === $dd['working_status'] ? null : (int) $dd['working_status'];
        }
        if ([] !== ($dd['sub'] ?? [])) {
            $d->sub = array_map(Group::fromArray(...), array_values((array) $dd['sub']));
        }
        if ([] !== ($dd['groups'] ?? [])) {
            $d->groups = array_map(Group::fromArray(...), array_values((array) $dd['groups']));
        }

        return $d;
    }

    /**
     * Every setting as write commands, for uploading a preset file to the device.
     * Values the model does not know (null) are skipped.
     *
     * @return list<Frame>
     */
    public function allFrames(): array
    {
        $frames = [];
        foreach ($this->inputs as $ch => $c) {
            array_push(
                $frames,
                Commands::channelName($ch, $c->name),
                Commands::gain($ch, $c->gain),
                Commands::mute($ch, $c->mute),
                Commands::phase($ch, $c->phase),
                Commands::gate($ch, $c->gate->attack, $c->gate->release, $c->gate->threshold),
                Commands::highPass($ch, $c->hpf->freq, $c->hpf->slope),
                Commands::lowPass($ch, $c->lpf->freq, $c->lpf->slope),
                ...self::peqFrames($ch, $c->peq),
            );
            $frames[] = Commands::link($ch, $c->link);
            if (null !== $c->mode) {
                $frames[] = Commands::inputMode($ch, $c->mode);
            }
            if (null !== $c->fbq) {
                $frames[] = Commands::fbq($ch, $c->fbq);
            }
        }
        foreach ($this->outputs as $k => $c) {
            $ch = Channel::output($k + 1);
            array_push(
                $frames,
                Commands::channelName($ch, $c->name),
                Commands::routing($ch, $c->route),
                Commands::gain($ch, $c->gain),
                Commands::mute($ch, $c->mute),
                Commands::phase($ch, $c->phase),
                Commands::delay($ch, $c->delay),
                Commands::highPass($ch, $c->hpf->freq, $c->hpf->slope),
                Commands::lowPass($ch, $c->lpf->freq, $c->lpf->slope),
                Commands::compressor($ch, $c->comp->ratio, $c->comp->knee, $c->comp->attack, $c->comp->release, $c->comp->threshold),
                ...self::peqFrames($ch, $c->peq),
            );
            $frames[] = Commands::link($ch, $c->link);
        }
        $a = $this->aux;
        array_push(
            $frames,
            Commands::routing(Channel::AUX, $a->select),
            Commands::gain(Channel::AUX, $a->gain),
            Commands::compressor(Channel::AUX, $a->comp->ratio, $a->comp->knee, $a->comp->attack, $a->comp->release, $a->comp->threshold),
            ...self::peqFrames(Channel::AUX, $a->peq),
        );
        foreach ([Channel::AUX => $a->automix, Channel::CAMERA => $a->camera] as $ch => $m) {
            $frames[] = Commands::mixFunction($ch, $m->attack, $m->release, $m->threshold, $m->select, $m->on);
        }
        $e = $a->effect;
        $frames[] = Commands::echo($e->echoPredelay, $e->echoDelay, $e->echoRepeat, $e->echo);
        $frames[] = Commands::reverb($e->reverbPredelay, $e->reflect, $e->time, $e->reverb);
        if (null !== $e->on) {
            $frames[] = Commands::effect($e->on, $e->gain);
        }
        if (null !== $a->automixSlope) {
            $frames[] = Commands::autoMixSlope($a->automixSlope);
        }
        if (null !== $a->mute) {
            $frames[] = Commands::mute(Channel::AUX, $a->mute);
        }

        return $frames;
    }

    /**
     * @param list<PeqBand> $bands
     *
     * @return list<Frame>
     */
    private static function peqFrames(int $channel, array $bands): array
    {
        return array_map(
            static fn (int $i): Frame => Commands::peq($channel, $i, $bands[$i]->gain, $bands[$i]->freq, $bands[$i]->q, $bands[$i]->type, $bands[$i]->bypass),
            array_keys($bands),
        );
    }

    /**
     * @param list<PeqBand> $bands
     */
    private static function applyBandBypass(array $bands, int $mask): void
    {
        foreach ($bands as $j => $band) {
            $band->bypass = (bool) ($mask >> $j & 1);
        }
    }

    /**
     * @param list<PeqBand> $bands
     */
    private static function restoreBandBypass(array $bands, mixed $saved): void
    {
        foreach (array_values(\is_array($saved) ? $saved : []) as $j => $band) {
            if (isset($bands[$j]) && \is_array($band)) {
                $bands[$j]->bypass = (bool) ($band['bypass'] ?? false);
            }
        }
    }
}
