<?php

namespace App\Controller;

use App\AutoEq\AutoEq;
use App\AutoEq\FitSettings;
use App\AutoEq\FittedBand;
use App\AutoEq\PeqFitter;
use App\AutoEq\Session;
use App\AutoEq\TargetCurve;
use App\Bridge\BridgeException;
use App\Measurement\Calibration;
use App\Measurement\Response as Spectrum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The auto EQ of the browser editor, one step per request: start a session with a backup, record or upload
 * one mic position at a time, fit as often as wanted, apply, verify, revert. The session is a file, so
 * every step works after a reload of the page. Errors are {"error": "..."} as in the ApiController.
 */
#[Route('/api/autoeq', format: 'json')]
#[AsController]
final class AutoEqController
{
    private const MAX_UPLOAD = 64 * 1024 * 1024;

    public function __construct(
        private readonly AutoEq $autoEq,
    ) {
    }

    /**
     * The session, the capture devices with the configured one and the backups.
     */
    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $recorders = $this->autoEq->recorders();

        return new JsonResponse([
            'session' => $this->autoEq->session()?->toPublic(),
            'recorder' => $recorders->available(),
            'devices' => $recorders->devices(),
            'device' => $recorders->defaultDevice(),
            'backups' => $this->autoEq->backups(),
        ]);
    }

    /**
     * {"outputs": [8, 9], "bands": 7, "device": "default", "mic_channel": 1, "seconds": 8, "settle": 2}.
     * Backs up the device and replaces the previous session.
     */
    #[Route('/start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): array {
            $body = self::body($request);
            $state = $this->autoEq->state();
            $outputs = array_values(array_unique(array_filter((array) ($body['outputs'] ?? []), 'is_int')));
            $stamp = date('Ymd-His');
            $session = new Session(
                $stamp, $outputs, self::int($body, 'bands', 7), self::string($body, 'device', $this->autoEq->recorders()->defaultDevice()),
                self::int($body, 'mic_channel', 1), self::int($body, 'seconds', 8), self::float($body, 'settle', 2.0),
                $this->autoEq->path('backup-'.$stamp.'.json'),
            );
            $this->discardFiles();
            $this->autoEq->backup($state, $outputs, $stamp);
            $this->autoEq->saveSession($session);

            return ['session' => $session->toPublic()];
        });
    }

    /**
     * Records one position with pink noise, {"verify": true} with the bands on. Blocks for the recording.
     */
    #[Route('/record', methods: ['POST'])]
    public function record(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): array {
            $session = $this->session();
            $verify = true === (self::body($request)['verify'] ?? false);
            $lock = fopen($this->autoEq->path('record.lock'), 'c');
            if (false === $lock || !flock($lock, \LOCK_EX | \LOCK_NB)) {
                throw new BridgeException('Another recording is running.', 409);
            }
            try {
                set_time_limit($session->seconds + (int) ceil($session->settle) + 60);
                $n = \count($session->recordings) + 1;
                $path = $this->autoEq->path(\sprintf('measure-%s-%d.wav', $session->id, $n));
                $this->autoEq->record($this->autoEq->state(), $session->outputs, $verify ? 0 : $session->bands, $session->device, $session->micChannel, $session->seconds, $session->settle, $path);
            } finally {
                flock($lock, \LOCK_UN);
                fclose($lock);
            }

            return $this->add($session, $path, ($verify ? 'Verify ' : 'Position ').$n, $verify);
        });
    }

    /**
     * A WAV file as the body instead of a recording, ?name=...&verify=1.
     */
    #[Route('/upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): array {
            $session = $this->session();
            $content = $request->getContent();
            if ('' === $content || \strlen($content) > self::MAX_UPLOAD) {
                throw new \InvalidArgumentException('Send a WAV file of up to 64 MB.');
            }
            $n = \count($session->recordings) + 1;
            $path = $this->autoEq->path(\sprintf('upload-%s-%d.wav', $session->id, $n));
            file_put_contents($path, $content);
            $name = trim((string) $request->query->get('name', ''));

            return $this->add($session, $path, '' !== $name ? mb_substr($name, 0, 60) : 'Upload '.$n, $request->query->getBoolean('verify'));
        });
    }

    /**
     * {"index": 0} removes one recording.
     */
    #[Route('/remove', methods: ['POST'])]
    public function remove(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): array {
            $session = $this->session();
            $index = self::int(self::body($request), 'index', -1);
            if (!isset($session->recordings[$index])) {
                throw new \InvalidArgumentException('No such recording.');
            }
            @unlink($session->recordings[$index]['file']);
            array_splice($session->recordings, $index, 1);
            $this->autoEq->saveSession($session);

            return ['session' => $session->toPublic()];
        });
    }

    /**
     * The mic calibration file as the body ("frequency dB" lines), an empty body removes it.
     */
    #[Route('/calibration', methods: ['POST'])]
    public function calibration(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): array {
            $session = $this->session();
            if (null !== $session->calibration) {
                @unlink($session->calibration);
                $session->calibration = null;
            }
            $content = $request->getContent();
            if ('' !== trim($content)) {
                Calibration::parse($content);
                $session->calibration = $this->autoEq->path('calibration-'.$session->id.'.txt');
                file_put_contents($session->calibration, $content);
            }
            $this->autoEq->saveSession($session);

            return ['session' => $session->toPublic()];
        });
    }

    /**
     * {"target": "flat", "low": 60, "high": 16000, "max_boost": 3, "max_cut": 9, "max_q": 5, "tolerance": 1}.
     * Fits the average of the positions and returns the bands with the curves to draw.
     */
    #[Route('/fit', methods: ['POST'])]
    public function fit(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): array {
            $session = $this->session();
            $body = self::body($request);
            $maxQ = self::float($body, 'max_q', 5.0);
            $settings = new FitSettings(
                $session->bands, self::float($body, 'low', 60.0), self::float($body, 'high', 16000.0),
                self::float($body, 'max_boost', 3.0), self::float($body, 'max_cut', 9.0), $maxQ, min(2.0, $maxQ),
                self::float($body, 'tolerance', 1.0),
            );
            $spec = self::string($body, 'target', 'flat');
            if (!preg_match('/^(flat|tilt:[-+]?\d+(\.\d+)?)$/', $spec)) {
                throw new \InvalidArgumentException('The target must be flat or tilt:<dB per octave>.');
            }
            $target = TargetCurve::parse($spec);
            $calibration = null === $session->calibration ? null : Calibration::read($session->calibration);
            $grid = Spectrum::grid();
            $spectra = static fn (bool $verify): array => array_values(array_map(
                static fn (array $r): Spectrum => new Spectrum($grid, $r['db']),
                array_filter($session->recordings, static fn (array $r): bool => $verify === $r['verify']),
            ));
            $measured = AutoEq::average($spectra(false), $calibration);
            $result = (new PeqFitter())->fit($measured, $target, $settings);
            $fit = AutoEq::describe($measured, $target, $settings, $result);
            if ([] !== $verified = $spectra(true)) {
                // The verify positions at the level of the prediction, so both curves compare directly
                $average = AutoEq::average($verified, $calibration);
                $after = new Spectrum($grid, $fit['after']);
                $shift = $after->mean($settings->low, $settings->high) - $average->mean($settings->low, $settings->high);
                $fit['verified'] = array_map(static fn (float $v): float => round($v + $shift, 2), $average->db);
            }
            $session->fit = ['settings' => [
                'target' => $spec, 'low' => $settings->low, 'high' => $settings->high,
                'max_boost' => $settings->maxBoostDb, 'max_cut' => $settings->maxCutDb, 'max_q' => $settings->maxQ, 'tolerance' => $settings->toleranceDb,
            ], 'result' => $fit];
            $this->autoEq->saveSession($session);

            return ['session' => $session->toPublic()];
        });
    }

    /**
     * Writes the bands of the last fit.
     */
    #[Route('/apply', methods: ['POST'])]
    public function apply(): JsonResponse
    {
        return $this->call(function (): array {
            $session = $this->session();
            $result = $session->fit['result'] ?? null;
            $bands = \is_array($result) ? ($result['bands'] ?? null) : null;
            if (!\is_array($bands)) {
                throw new \InvalidArgumentException('Fit first.');
            }
            $fitted = [];
            foreach ($bands as $band) {
                if (\is_array($band) && \is_int($band['freq'] ?? null) && \is_int($band['gain'] ?? null) && \is_int($band['q'] ?? null)) {
                    $fitted[] = new FittedBand($band['freq'], $band['gain'], $band['q']);
                }
            }
            $this->autoEq->apply($this->autoEq->state(), $session->outputs, $session->bands, $fitted);
            $session->applied = true;
            $this->autoEq->saveSession($session);

            return ['session' => $session->toPublic()];
        });
    }

    /**
     * {"backup": "backup-20260927-120000.json"} restores the PEQ of the outputs the backup names, the
     * session's backup without a name.
     */
    #[Route('/revert', methods: ['POST'])]
    public function revert(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): array {
            $session = $this->autoEq->session();
            $name = self::body($request)['backup'] ?? (null === $session ? '' : basename($session->backup));
            if (!\is_string($name) || !preg_match('/^backup-[\w-]+\.json$/', $name) || null === $path = $this->autoEq->file($name)) {
                throw new \InvalidArgumentException('No such backup.');
            }
            $outputs = $this->autoEq->revert($path);
            if (null !== $session && basename($session->backup) === $name) {
                $session->applied = false;
                $this->autoEq->saveSession($session);
            }

            return ['session' => $session?->toPublic(), 'outputs' => $outputs];
        });
    }

    /**
     * Ends the session and deletes its recordings. Backups stay.
     */
    #[Route('/discard', methods: ['POST'])]
    public function discard(): JsonResponse
    {
        return $this->call(function (): array {
            $this->discardFiles();
            $this->autoEq->clearSession();

            return ['session' => null];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function add(Session $session, string $path, string $name, bool $verify): array
    {
        try {
            $spectrum = AutoEq::spectrum($path, $session->micChannel);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException ? $e : new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        $session->recordings[] = ['name' => $name, 'file' => $path, 'db' => array_map(static fn (float $v): float => round($v, 3), $spectrum->db), 'verify' => $verify];
        $this->autoEq->saveSession($session);

        return ['session' => $session->toPublic()];
    }

    private function discardFiles(): void
    {
        $session = $this->autoEq->session();
        foreach ($session->recordings ?? [] as $recording) {
            @unlink($recording['file']);
        }
        if (null !== $session?->calibration) {
            @unlink($session->calibration);
        }
    }

    private function session(): Session
    {
        return $this->autoEq->session() ?? throw new \InvalidArgumentException('Start an auto EQ session first.');
    }

    /**
     * @param \Closure(): array<string, mixed> $call
     */
    private function call(\Closure $call): JsonResponse
    {
        try {
            return new JsonResponse($call());
        } catch (BridgeException $e) {
            $code = \in_array($e->getCode(), [400, 409, 503, 504], true) ? $e->getCode() : 500;

            return new JsonResponse(['error' => $e->getMessage()], $code);
        } catch (\InvalidArgumentException|\LengthException|\JsonException|\OutOfRangeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @return array<mixed>
     */
    private static function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        try {
            return $request->toArray();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('The body must be a JSON object.', 0, $e);
        }
    }

    /**
     * @param array<mixed> $body
     */
    private static function int(array $body, string $key, int $default): int
    {
        $value = $body[$key] ?? $default;
        if (!\is_int($value) && !(\is_string($value) && preg_match('/^-?\d+$/', $value))) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a whole number.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<mixed> $body
     */
    private static function float(array $body, string $key, float $default): float
    {
        $value = $body[$key] ?? $default;
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a number.', $key));
        }

        return (float) $value;
    }

    /**
     * @param array<mixed> $body
     */
    private static function string(array $body, string $key, string $default): string
    {
        $value = $body[$key] ?? $default;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a text.', $key));
        }

        return trim($value);
    }
}
