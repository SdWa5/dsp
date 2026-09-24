<?php

namespace App\Controller;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Bridge\CommandMap;
use App\Model\Device;
use App\Protocol\Frame;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON API of the web editor. Every call goes through the bridge. Errors are {"error": "..."} with
 * 400 (bad request), 409 (device not connected), 503 (bridge not running) or 504 (bridge timeout).
 */
#[Route('/api', format: 'json')]
#[AsController]
final class ApiController
{
    public function __construct(private readonly BridgeClient $bridge)
    {
    }

    #[Route('/status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->call(fn (): mixed => $this->bridge->request('status'));
    }

    #[Route('/state', methods: ['GET'])]
    public function state(): JsonResponse
    {
        return $this->call(fn (): mixed => $this->bridge->request('state'));
    }

    /**
     * One command {"command": "mute", "args": {"channel": 8, "on": true}} or a list of them in "commands".
     */
    #[Route('/write', methods: ['POST'])]
    public function write(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): mixed {
            $body = self::body($request);
            $commands = \is_array($body['commands'] ?? null) ? $body['commands'] : [$body];
            $frames = [];
            foreach ($commands as $command) {
                if (!\is_array($command) || !\is_string($command['command'] ?? null)) {
                    throw new BridgeException('Every command needs a "command" name.', 400);
                }
                $args = $command['args'] ?? [];
                if (!\is_array($args)) {
                    throw new BridgeException('"args" must be an object.', 400);
                }
                try {
                    $frames[] = CommandMap::build($command['command'], $args);
                } catch (\InvalidArgumentException $e) {
                    throw new BridgeException($e->getMessage(), 400, $e);
                }
            }

            return $this->bridge->request('write', ['frames' => array_map(self::hex(...), $frames)]);
        });
    }

    #[Route('/connect', methods: ['POST'])]
    public function connect(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): mixed {
            $target = self::body($request)['target'] ?? null;

            return $this->bridge->request('connect', null === $target ? [] : ['target' => $target]);
        });
    }

    #[Route('/disconnect', methods: ['POST'])]
    public function disconnect(): JsonResponse
    {
        return $this->call(fn (): mixed => $this->bridge->request('disconnect'));
    }

    #[Route('/refresh', methods: ['POST'])]
    public function refresh(): JsonResponse
    {
        return $this->call(fn (): mixed => $this->bridge->request('refresh'));
    }

    /**
     * Sends every setting of a preset file (request body) to the device.
     */
    #[Route('/upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        return $this->call(function () use ($request): mixed {
            try {
                $device = Device::fromJson($request->getContent());
            } catch (\JsonException|\InvalidArgumentException|\LengthException $e) {
                throw new BridgeException('Not a valid preset file: '.$e->getMessage(), 400, $e);
            }

            return $this->bridge->request('write', ['frames' => array_map(self::hex(...), $device->allFrames())]);
        });
    }

    /**
     * The live state as a preset file.
     */
    #[Route('/download', methods: ['GET'])]
    public function download(): Response
    {
        try {
            $state = $this->bridge->request('state');
        } catch (BridgeException $e) {
            return self::error($e);
        }
        $json = json_encode(
            ['format' => Device::FILE_FORMAT, 'version' => Device::FILE_VERSION, 'device' => $state],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
        $name = \is_array($state) && \is_string($state['preset_name'] ?? null) && '' !== trim($state['preset_name']) ? trim($state['preset_name']) : 'preset';

        return new Response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => \sprintf('attachment; filename="%s.json"', preg_replace('/[^A-Za-z0-9._-]+/', '_', $name)),
        ]);
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function call(\Closure $call): JsonResponse
    {
        try {
            return new JsonResponse($call());
        } catch (BridgeException $e) {
            return self::error($e);
        }
    }

    private static function error(BridgeException $e): JsonResponse
    {
        $code = \in_array($e->getCode(), [400, 409, 503, 504], true) ? $e->getCode() : 500;

        return new JsonResponse(['error' => $e->getMessage()], $code);
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
            $body = $request->toArray();
        } catch (\Throwable $e) {
            throw new BridgeException('The body must be a JSON object.', 400, $e);
        }

        return $body;
    }

    private static function hex(Frame $frame): string
    {
        return bin2hex(\chr($frame->cmd).$frame->data);
    }
}
