<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Entity\Company;
use App\Domain\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/company/settings', name: 'app_admin_company_settings_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCompanySettingsController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/company_settings/index.html.twig', [
            'company' => $this->requireCompany(),
            'today' => \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0),
        ]);
    }

    #[Route('/2fa-requirement', name: 'set_2fa_requirement', methods: ['POST'])]
    public function set2faRequirement(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('company_settings_2fa', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $this->requireCompany();
        $enable = '1' === $request->request->get('enable');

        if (!$enable) {
            $company->disableTwoFactorRequirement();
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('admin.company_settings.flash.2fa_disabled'));

            return $this->redirectToRoute('app_admin_company_settings_index');
        }

        $enforcedFromRaw = trim((string) $request->request->get('enforced_from'));
        if ('' === $enforcedFromRaw) {
            $this->addFlash('error', $this->translator->trans('admin.company_settings.flash.2fa_invalid_date'));

            return $this->redirectToRoute('app_admin_company_settings_index');
        }

        try {
            $enforcedFrom = new \DateTimeImmutable($enforcedFromRaw);
        } catch (\Exception) {
            $this->addFlash('error', $this->translator->trans('admin.company_settings.flash.2fa_invalid_date'));

            return $this->redirectToRoute('app_admin_company_settings_index');
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            $company->enableTwoFactorRequirement($enforcedFrom, $now);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $this->translator->trans('admin.company_settings.flash.2fa_past_date'));

            return $this->redirectToRoute('app_admin_company_settings_index');
        }

        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('admin.company_settings.flash.2fa_enabled', [
            '%date%' => $enforcedFrom->format('d.m.Y'),
        ]));

        return $this->redirectToRoute('app_admin_company_settings_index');
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
