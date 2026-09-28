<?php

namespace App\Controller;

use App\Automation\RiderSettings;
use App\Model\FactoryDefaults;
use App\Protocol\Labels;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The web editor. The page is static: every widget binds to a path of the device state that the
 * browser receives from /api/events, and writes through /api/write. The gain riders and the auto EQ have
 * their own endpoints. The factory defaults are rendered into the page, so the editor marks every setting
 * that differs from them.
 */
final class EditorController extends AbstractController
{
    public function __construct(
        #[Autowire('%trackdsp.factory_config%')]
        private readonly string $factoryConfig,
    ) {
    }

    #[Route('/', name: 'editor', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('editor/index.html.twig', [
            'labels' => (new \ReflectionClass(Labels::class))->getConstants(),
            'device_ids' => array_combine(range(1, 254), range(1, 254)),
            'rider_defaults' => (new RiderSettings(0))->toArray(),
            'defaults' => FactoryDefaults::fromFile($this->factoryConfig),
        ]);
    }
}
