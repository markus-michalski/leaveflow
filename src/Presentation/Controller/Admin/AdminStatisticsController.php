<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Statistics\MonthlyDistributionFormatter;
use App\Application\Statistics\StatisticsService;
use App\Domain\Entity\Company;
use App\Domain\Repository\CompanyRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
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
        private readonly MonthlyDistributionFormatter $monthlyFormatter,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
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

    #[Route('/export.pdf', name: 'export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
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
        $monthlyStats = $this->monthlyFormatter->summarize($snapshot->monthlyDistribution);

        // dompdf reads images via the local filesystem path (isRemoteEnabled=false),
        // so we resolve the company logo to an absolute path the renderer can open.
        $logoAbsolutePath = null;
        if (null !== $company->getLogoPath()) {
            $candidate = $this->publicDir.'/'.$company->getLogoPath();
            if (is_file($candidate)) {
                $logoAbsolutePath = $candidate;
            }
        }

        $html = $this->renderView('admin/statistics/export.pdf.html.twig', [
            'snapshot' => $snapshot,
            'monthlyStats' => $monthlyStats,
            'generatedAt' => $now,
            'company' => $company,
            'logoAbsolutePath' => $logoAbsolutePath,
        ]);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = \sprintf('leaveflow-statistik-%d.pdf', $year);

        return new Response(
            (string) $dompdf->output(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => \sprintf('attachment; filename="%s"', $filename),
                'Cache-Control' => 'private, no-store',
            ],
        );
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
