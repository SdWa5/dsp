<?php

namespace App\Tests\EventListener;

use App\EventListener\LocaleListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LocaleListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string, ?string, string}>
     */
    public static function requests(): iterable
    {
        yield 'nothing set' => [null, '', null, 'en'];
        yield 'browser German' => [null, '', 'de-AT,de;q=0.9,en;q=0.5', 'de'];
        yield 'browser French' => [null, '', 'fr-FR,fr;q=0.9', 'en'];
        yield 'server beats browser' => [null, 'en', 'de-DE', 'en'];
        yield 'cookie English beats German' => ['en', 'de', 'de-DE', 'en'];
        yield 'cookie beats server' => ['de', 'en', 'en-US', 'de'];
        yield 'unknown cookie falls through' => ['fr', 'de', 'en-US', 'de'];
        yield 'unknown server setting falls through' => [null, 'fr', 'de-CH', 'de'];
    }

    public function testApiRequestsKeepTheirDefaultLocale(): void
    {
        $request = Request::create('/api/state');
        $request->attributes->set('_route', 'api_state');
        $request->cookies->set(LocaleListener::COOKIE, 'de');
        $kernel = $this->createStub(HttpKernelInterface::class);
        (new LocaleListener(['en', 'de'], 'en', 'de'))(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('en', $request->getLocale());
    }

    #[DataProvider('requests')]
    public function testPicksTheFirstEnabledLocale(?string $cookie, string $server, ?string $acceptLanguage, string $expected): void
    {
        $request = Request::create('/');
        $request->attributes->set('_route', 'editor');
        $request->headers->remove('Accept-Language');
        if (null !== $acceptLanguage) {
            $request->headers->set('Accept-Language', $acceptLanguage);
        }
        if (null !== $cookie) {
            $request->cookies->set(LocaleListener::COOKIE, $cookie);
        }
        $kernel = $this->createStub(HttpKernelInterface::class);
        (new LocaleListener(['en', 'de'], 'en', $server))(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame($expected, $request->getLocale());
    }
}
