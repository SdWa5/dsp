<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes to the API need the header X-Sdwa5-Dsp. A custom header makes the browser ask before a
 * cross-origin request (CORS preflight), which this app never allows, so another website open in the
 * same browser cannot mute or reconfigure the device. Reads stay open, the same-origin policy keeps
 * their answers from other sites.
 */
#[AsEventListener(KernelEvents::REQUEST, priority: 30)]
final class ApiGuardListener
{
    public const HEADER = 'X-Sdwa5-Dsp';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/') || $request->isMethodSafe()) {
            return;
        }
        if (!$request->headers->has(self::HEADER)) {
            $event->setResponse(new JsonResponse(['error' => \sprintf('Writes need the header %s.', self::HEADER)], 403));
        }
    }
}
