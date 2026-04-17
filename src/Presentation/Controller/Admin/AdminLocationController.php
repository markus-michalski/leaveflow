<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Entity\Company;
use App\Domain\Entity\Location;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\LocationRepository;
use App\Presentation\Form\LocationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/locations', name: 'app_admin_location_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminLocationController extends AbstractController
{
    public function __construct(
        private readonly LocationRepository $locationRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/locations/index.html.twig', [
            'locations' => $this->locationRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured.');
        }

        $form = $this->createForm(LocationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string, country: string, federalState: string, city: string} $data */
            $data = [
                'name' => (string) $form->get('name')->getData(),
                'country' => (string) $form->get('country')->getData(),
                'federalState' => (string) $form->get('federalState')->getData(),
                'city' => (string) $form->get('city')->getData(),
            ];

            $location = new Location($company, $data['name'], $data['country'], $data['federalState'], $data['city']);
            $this->entityManager->persist($location);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('admin.locations.flash.created', ['%name%' => $location->getName()]));

            return $this->redirectToRoute('app_admin_location_index');
        }

        return $this->render('admin/locations/form.html.twig', [
            'form' => $form,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Location $location): Response
    {
        $form = $this->createForm(LocationType::class, null, [
            // Prefill
        ]);
        $form->get('name')->setData($location->getName());
        $form->get('country')->setData($location->getCountry());
        $form->get('federalState')->setData($location->getFederalState());
        $form->get('city')->setData($location->getCity());

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $location->rename((string) $form->get('name')->getData());
            $location->moveTo(
                (string) $form->get('country')->getData(),
                (string) $form->get('federalState')->getData(),
                (string) $form->get('city')->getData(),
            );
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('admin.locations.flash.updated', ['%name%' => $location->getName()]));

            return $this->redirectToRoute('app_admin_location_index');
        }

        return $this->render('admin/locations/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'location' => $location,
        ]);
    }
}
