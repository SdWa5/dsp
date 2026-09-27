<?php

namespace App\Controller;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-sent events relayed from the bridge: "status", "state" and "meters", each with JSON data.
 * A stream ends after the configured lifetime and the browser's EventSource reconnects on its own.
 * Without a bridge the stream sends one "status" event with the error and ends.
 */
#[AsController]
final class EventsController
{
    public function __construct(
        private readonly BridgeClient $bridge,
        #[Autowire('%trackdsp.events_lifetime%')]
        private readonly int $lifetime = 300,
    ) {
    }

    #[Route('/api/events', methods: ['GET'])]
    public function __invoke(): StreamedResponse
    {
        $response = new StreamedResponse(function (): void {
            echo "retry: 1000\n\n";
            $this->flush();
            $end = microtime(true) + $this->lifetime;
            try {
                foreach ($this->bridge->events(min(15.0, (float) $this->lifetime)) as $event) {
                    if (null === $event) {
                        // Keep-alive comment, also how a closed browser tab is noticed
                        echo ": ping\n\n";
                    } else {
                        echo self::format($event['event'], $event['data']);
                    }
                    $this->flush();
                    if (connection_aborted() || microtime(true) >= $end) {
                        break;
                    }
                }
            } catch (BridgeException $e) {
                echo self::format('status', ['connected' => false, 'bridge' => false, 'error' => $e->getMessage()]);
                $this->flush();
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    public static function format(string $event, mixed $data): string
    {
        return \sprintf("event: %s\ndata: %s\n\n", $event, json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
