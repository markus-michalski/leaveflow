<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Statistics\StatisticsService;
use App\Domain\Entity\Company;
use App\Domain\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/statistics', name: 'app_admin_statistics_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminStatisticsController extends AbstractController
{
    public function __construct(
        private readonly StatisticsService $statistics,
        private readonly CompanyRepository $companyRepository,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->requireCompany();

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $currentYear = (int) $now->format('Y');

        $year = (int) $request->query->get('year', (string) $currentYear);
        if ($year < 1970 || $year > 2200) {
            $year = $currentYear;
        }

        $orphanLabel = $this->translator->trans('statistics.department.orphan');
        $snapshot = $this->statistics->buildDashboard($company, $year, $orphanLabel);

        return $this->render('admin/statistics/index.html.twig', [
            'snapshot' => $snapshot,
            'current_year' => $currentYear,
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
