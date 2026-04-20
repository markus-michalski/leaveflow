<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Presentation\Form\LeaveEntitlementExpiresAtFormType;
use App\Presentation\Form\LeaveEntitlementFormType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/entitlements', name: 'app_admin_entitlement_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminEntitlementController extends AbstractController
{
    public function __construct(
        private readonly LeaveEntitlementRepository $repository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->requireCompany();

        return $this->render('admin/entitlements/index.html.twig', [
            'entries' => $this->repository->findAllByCompany($company),
            'today' => new \DateTimeImmutable('today'),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->requireCompany();
        $form = $this->createForm(LeaveEntitlementFormType::class, null, ['company' => $company]);
        // Sensible defaults: current year, Regular type.
        $form->get('year')->setData((int) (new \DateTimeImmutable())->format('Y'));
        $form->get('type')->setData(LeaveEntitlementType::Regular);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Employee $employee */
            $employee = $form->get('employee')->getData();
            /** @var int $year */
            $year = $form->get('year')->getData();
            /** @var LeaveEntitlementType $type */
            $type = $form->get('type')->getData();
            $hoursGranted = (float) $form->get('hoursGranted')->getData();
            /** @var \DateTimeImmutable|null $expiresAt */
            $expiresAt = $form->get('expiresAt')->getData();

            try {
                $entry = new LeaveEntitlement($employee, $year, $type, $hoursGranted, $expiresAt);
                $this->entityManager->persist($entry);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError(
                    $this->translator->trans('admin.entitlements.flash.duplicate')
                ));

                return $this->render('admin/entitlements/form.html.twig', [
                    'form' => $form,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->render('admin/entitlements/form.html.twig', [
                    'form' => $form,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.entitlements.flash.created'));

            return $this->redirectToRoute('app_admin_entitlement_index');
        }

        return $this->render('admin/entitlements/form.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/expires', name: 'edit_expiry', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editExpiry(Request $request, LeaveEntitlement $entry): Response
    {
        // Guard: entitlement must belong to the current company.
        $company = $this->requireCompany();
        if ($entry->getEmployee()->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(LeaveEntitlementExpiresAtFormType::class);
        $form->get('expiresAt')->setData($entry->getExpiresAt());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \DateTimeImmutable|null $expiresAt */
            $expiresAt = $form->get('expiresAt')->getData();
            $entry->adjustExpiresAt($expiresAt);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('admin.entitlements.flash.expiry_updated'));

            return $this->redirectToRoute('app_admin_entitlement_index');
        }

        return $this->render('admin/entitlements/edit_expiry.html.twig', [
            'form' => $form,
            'entry' => $entry,
        ]);
    }

    private function requireCompany(): Company
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured.');
        }

        return $company;
    }
}
