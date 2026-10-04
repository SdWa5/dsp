<?php

namespace App\Controller;

use App\Automation\RiderSettings;
use App\Bridge\HistoryPatch;
use App\Model\FactoryDefaults;
use App\Protocol\Labels;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The web editor. The page is static: every widget binds to a path of the device state that the
 * browser receives from /api/events, and writes through /api/write. The gain riders and the auto EQ have
 * their own endpoints. The factory defaults are rendered into the page, so the editor marks every setting
 * that differs from them. The page is translated into the request's locale (App\EventListener\LocaleListener):
 * Twig through the "messages" domain, the word labels of the device lists through "labels", and the strings of
 * the Stimulus controllers through "js", which the page carries as JSON.
 */
final class EditorController extends AbstractController
{
    public function __construct(
        #[Autowire('%trackdsp.factory_config%')]
        private readonly string $factoryConfig,
        #[Autowire('%kernel.project_dir%/composer.json')]
        private readonly string $packageFile,
        #[Autowire(service: 'translator')]
        private readonly TranslatorInterface&TranslatorBagInterface $translator,
    ) {
    }

    #[Route('/', name: 'editor', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('editor/index.html.twig', [
            'labels' => $this->labels(),
            'history_units' => HistoryPatch::units(),
            'i18n' => $this->catalogue('js'),
            'device_ids' => array_combine(range(1, 254), range(1, 254)),
            'rider_defaults' => (new RiderSettings(0))->toArray(),
            'defaults' => FactoryDefaults::fromFile($this->factoryConfig),
            'version' => $this->version(),
        ]);
    }

    /** The declared application version, including uncommitted release changes. */
    private function version(): string
    {
        $package = json_decode((string) file_get_contents($this->packageFile), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($package) && \is_string($package['version'] ?? null) ? $package['version'] : '';
    }

    /**
     * The value lists of Labels with each word translated, numbers and units stay as they are.
     *
     * @return array<string, list<string>>
     */
    private function labels(): array
    {
        return array_map(
            fn (array $list): array => array_values(array_map(
                fn (string $label): string => $this->translator->trans($label, [], 'labels'),
                $list,
            )),
            (new \ReflectionClass(Labels::class))->getConstants(),
        );
    }

    /**
     * Every message of a domain in the current locale, the fallback locale filling the gaps.
     *
     * @return array<string, string>
     */
    private function catalogue(string $domain): array
    {
        $catalogue = $this->translator->getCatalogue();
        $messages = [];
        for ($c = $catalogue; null !== $c; $c = $c->getFallbackCatalogue()) {
            $messages += $c->all($domain);
        }

        return $messages;
    }
}
