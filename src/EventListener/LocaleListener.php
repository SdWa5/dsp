<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The language of the browser editor. The first that names an enabled locale wins: the cookie of the
 * Language menu, the server setting TRACKDSP_LOCALE, the browser's Accept-Language, then English.
 * Runs before Symfony's own locale listeners (16 and 15), which keep a locale already set and hand it
 * to the translator.
 */
#[AsEventListener(KernelEvents::REQUEST, priority: 20)]
final class LocaleListener
{
    public const COOKIE = 'trackdsp_locale';

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
        #[Autowire('%env(TRACKDSP_LOCALE)%')]
        private readonly string $serverLocale,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || 'editor' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }
        $request = $event->getRequest();
        foreach ([$request->cookies->get(self::COOKIE), $this->serverLocale] as $locale) {
            if (\in_array($locale, $this->enabledLocales, true)) {
                $request->setLocale($locale);

                return;
            }
        }
        $request->setLocale($request->headers->has('Accept-Language')
            ? $request->getPreferredLanguage($this->enabledLocales) ?? $this->defaultLocale
            : $this->defaultLocale);
    }
}
