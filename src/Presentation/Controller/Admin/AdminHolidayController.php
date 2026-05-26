<?php

declare(strict_types=1);

/*
 * This file is part of LeaveFlow.
 *
 * (c) Markus Michalski <ich@markus-michalski.net>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace App\Presentation\Controller\Admin;

use App\Application\Holiday\HolidayService;
use App\Domain\Entity\Company;
use App\Domain\Enum\FederalState;
use App\Domain\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/holidays', name: 'app_admin_holiday_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminHolidayController extends AbstractController
{
    public function __construct(
        private readonly HolidayService $holidayService,
        private readonly CompanyRepository $companyRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->requireCompany();

        $currentYear = (int) $this->clock->now()->format('Y');
        $year = $this->resolveYear($request, $currentYear);
        $state = $this->resolveState($request);

        $holidays = $this->holidayService->getHolidaysForCompany($company, $state, $year);

        return $this->render('admin/holidays/index.html.twig', [
            'year' => $year,
            'state' => $state,
            'states' => FederalState::cases(),
            'years' => $this->yearRange($currentYear),
            'holidays' => $holidays,
        ]);
    }

    private function resolveYear(Request $request, int $fallback): int
    {
        $input = $request->query->get('year');
        if (!is_numeric($input)) {
            return $fallback;
        }
        $value = (int) $input;

        return ($value >= 1970 && $value <= 2200) ? $value : $fallback;
    }

    private function resolveState(Request $request): FederalState
    {
        $input = $request->query->get('state');
        if (!\is_string($input) || '' === $input) {
            return FederalState::Bayern;
        }

        return FederalState::tryFrom($input) ?? FederalState::Bayern;
    }

    /**
     * @return list<int>
     */
    private function yearRange(int $current): array
    {
        return range($current - 2, $current + 3);
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
