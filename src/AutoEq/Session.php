<?php

namespace App\AutoEq;

use App\Protocol\Channel;

/**
 * One auto EQ run in the browser editor: what is corrected, how it is measured, the recordings so far and
 * the last fit. It lives in a JSON file, so it survives a reload of the page.
 *
 * A recording keeps its spectrum on Response::grid() without the mic calibration, so a changed calibration
 * file needs no new analysis. A verify recording is taken with the bands on, after apply, and is compared
 * with the prediction instead of being fitted.
 */
final class Session
{
    /**
     * @param list<int>                                                              $outputs    output channel indices
     * @param list<array{name: string, file: string, db: list<float>, verify: bool}> $recordings verify: measured with the bands on
     * @param array<string, mixed>|null                                              $fit        settings and result of the last fit
     */
    public function __construct(
        public readonly string $id,
        public readonly array $outputs,
        public readonly int $bands,
        public readonly string $device,
        public readonly int $micChannel,
        public readonly int $seconds,
        public readonly float $settle,
        public readonly string $backup,
        public array $recordings = [],
        public ?string $calibration = null,
        public ?array $fit = null,
        public bool $applied = false,
    ) {
        if ([] === $outputs) {
            throw new \InvalidArgumentException('Choose at least one output.');
        }
        foreach ($outputs as $channel) {
            if (!Channel::isOutput($channel)) {
                throw new \InvalidArgumentException(\sprintf('The auto EQ corrects outputs only, got %s.', Channel::label($channel)));
            }
        }
        if ($bands < 1 || $bands > 7) {
            throw new \InvalidArgumentException('Use 1 to 7 bands.');
        }
        if ($micChannel < 1 || $micChannel > 32) {
            throw new \InvalidArgumentException('The mic channel counts from 1.');
        }
        if ($seconds < 2 || $seconds > 60) {
            throw new \InvalidArgumentException('Record 2 to 60 seconds per position.');
        }
        if ($settle < 0 || $settle > 10) {
            throw new \InvalidArgumentException('The settle time must be 0 to 10 seconds.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'outputs' => $this->outputs,
            'bands' => $this->bands,
            'device' => $this->device,
            'mic_channel' => $this->micChannel,
            'seconds' => $this->seconds,
            'settle' => $this->settle,
            'backup' => $this->backup,
            'recordings' => $this->recordings,
            'calibration' => $this->calibration,
            'fit' => $this->fit,
            'applied' => $this->applied,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $outputs = array_values(array_filter((array) ($data['outputs'] ?? []), 'is_int'));
        $recordings = [];
        foreach ((array) ($data['recordings'] ?? []) as $recording) {
            if (\is_array($recording) && \is_string($recording['name'] ?? null) && \is_string($recording['file'] ?? null) && \is_array($recording['db'] ?? null)) {
                $recordings[] = ['name' => $recording['name'], 'file' => $recording['file'], 'db' => array_values(array_map(floatval(...), $recording['db'])), 'verify' => true === ($recording['verify'] ?? false)];
            }
        }
        $fit = \is_array($data['fit'] ?? null) ? $data['fit'] : null;

        return new self(
            (string) ($data['id'] ?? ''),
            $outputs,
            (int) ($data['bands'] ?? 7),
            (string) ($data['device'] ?? 'default'),
            (int) ($data['mic_channel'] ?? 1),
            (int) ($data['seconds'] ?? 8),
            (float) ($data['settle'] ?? 2),
            (string) ($data['backup'] ?? ''),
            $recordings,
            \is_string($data['calibration'] ?? null) ? $data['calibration'] : null,
            null === $fit ? null : array_filter($fit, 'is_string', \ARRAY_FILTER_USE_KEY),
            true === ($data['applied'] ?? false),
        );
    }

    /**
     * What the browser needs: everything except the spectra of the single recordings and the server paths.
     *
     * @return array<string, mixed>
     */
    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'outputs' => $this->outputs,
            'labels' => array_map(Channel::label(...), $this->outputs),
            'bands' => $this->bands,
            'device' => $this->device,
            'mic_channel' => $this->micChannel,
            'seconds' => $this->seconds,
            'settle' => $this->settle,
            'backup' => basename($this->backup),
            'recordings' => array_map(static fn (array $r): array => ['name' => $r['name'], 'file' => basename($r['file']), 'verify' => $r['verify']], $this->recordings),
            'calibration' => null === $this->calibration ? null : basename($this->calibration),
            'fit' => $this->fit,
            'applied' => $this->applied,
        ];
    }
}
