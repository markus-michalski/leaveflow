<?php

declare(strict_types=1);

namespace App\Presentation\Controller\My;

use App\Application\Approval\ApprovalWorkflow;
use App\Application\Approval\CancellationNotAllowedException;
use App\Application\Approval\InvalidTransitionException;
use App\Application\Calendar\BlackoutPeriodViolationException;
use App\Application\Calendar\TeamCapacityQuery;
use App\Application\Leave\BackdatedLeaveRequestException;
use App\Application\Leave\InsufficientLeaveBalanceException;
use App\Application\Leave\LeaveRequestService;
use App\Application\Leave\MultiDayHalfDayException;
use App\Application\Leave\NoEntitlementForYearException;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Employee;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\LeaveRequestAuditEntryRepository;
use App\Domain\Repository\LeaveRequestRepository;
use App\Presentation\Form\LeaveRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/my/leave-requests', name: 'app_my_leave_request_')]
#[IsGranted('ROLE_USER')]
final class MyLeaveRequestController extends AbstractController
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly LeaveRequestRepository $leaveRequestRepository,
        private readonly LeaveRequestAuditEntryRepository $auditRepository,
        private readonly LeaveRequestService $service,
        private readonly ApprovalWorkflow $approvalWorkflow,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TeamCapacityQuery $teamCapacityQuery,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $employee = $this->getEmployeeOrRedirect();
        if ($employee instanceof Response) {
            return $employee;
        }

        return $this->render('my/leave_request/index.html.twig', [
            'employee' => $employee,
            'requests' => $this->leaveRequestRepository->findBy(
                ['employee' => $employee],
                ['requestedAt' => 'DESC'],
            ),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $employee = $this->getEmployeeOrRedirect();
        if ($employee instanceof Response) {
            return $employee;
        }

        $form = $this->createForm(LeaveRequestFormType::class, null, [
            'company' => $employee->getCompany(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \DateTimeImmutable $start */
            $start = $form->get('startDate')->getData();
            /** @var \DateTimeImmutable $end */
            $end = $form->get('endDate')->getData();
            /** @var LeaveDayType $dayType */
            $dayType = $form->get('dayType')->getData();
            /** @var AbsenceType $absenceType */
            $absenceType = $form->get('absenceType')->getData();

            if ($end < $start) {
                $form->get('endDate')->addError(new FormError(
                    $this->translator->trans('my.leave_requests.error.end_before_start'),
                ));
            } elseif ($absenceType->getCompany() !== $employee->getCompany()) {
                $form->get('absenceType')->addError(new FormError(
                    $this->translator->trans('my.leave_requests.error.absence_type_foreign'),
                ));
            } else {
                try {
                    $leaveRequest = $this->service->create($employee, $absenceType, $start, $end, $dayType);
                    $this->addFlash('success', $this->translator->trans(
                        'my.leave_requests.flash.created',
                        ['%hours%' => number_format($leaveRequest->getTotalHours(), 2, ',', '.')],
                    ));

                    return $this->redirectToRoute('app_my_leave_request_index');
                } catch (BackdatedLeaveRequestException) {
                    $form->get('startDate')->addError(new FormError(
                        $this->translator->trans('my.leave_requests.error.backdated'),
                    ));
                } catch (MultiDayHalfDayException) {
                    $form->get('dayType')->addError(new FormError(
                        $this->translator->trans('my.leave_requests.error.half_day_multi_day'),
                    ));
                } catch (BlackoutPeriodViolationException $e) {
                    $reasons = array_map(
                        static fn ($period) => $period->getReason(),
                        $e->blackoutPeriods,
                    );
                    $form->get('startDate')->addError(new FormError(
                        $this->translator->trans('my.leave_requests.error.blackout_period', [
                            '%reasons%' => implode(', ', $reasons),
                        ]),
                    ));
                } catch (NoEntitlementForYearException $e) {
                    $form->addError(new FormError(
                        $this->translator->trans('my.leave_requests.error.no_entitlement_for_year', [
                            '%year%' => (string) $e->year,
                        ]),
                    ));
                } catch (InsufficientLeaveBalanceException $e) {
                    $form->addError(new FormError(
                        $this->translator->trans('my.leave_requests.error.insufficient_balance', [
                            '%year%' => (string) $e->year,
                            '%requested%' => number_format($e->requestedHours, 2, ',', '.'),
                            '%available%' => number_format($e->availableHours, 2, ',', '.'),
                        ]),
                    ));
                }
            }
        }

        return $this->render('my/leave_request/new.html.twig', [
            'form' => $form,
            'employee' => $employee,
        ]);
    }

    /**
     * Turbo-Frame endpoint called by the live-preview Stimulus controller
     * whenever start/end/dayType change on the form. Returns a partial that
     * replaces the <turbo-frame id="leave-preview"> block on the new page.
     */
    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $employee = $this->getEmployeeOrRedirect();
        if ($employee instanceof Response) {
            return $employee;
        }

        $startRaw = (string) $request->query->get('start_date', '');
        $endRaw = (string) $request->query->get('end_date', '');
        $dayTypeRaw = (string) $request->query->get('day_type', LeaveDayType::FullDay->value);

        $breakdown = null;
        $errorKey = null;
        $peerAbsenceCount = 0;

        $start = $this->parseDate($startRaw);
        $end = $this->parseDate($endRaw);

        if (null === $start || null === $end) {
            $errorKey = 'my.leave_requests.preview.awaiting_dates';
        } elseif ($end < $start) {
            $errorKey = 'my.leave_requests.preview.end_before_start';
        } else {
            $dayType = LeaveDayType::tryFrom($dayTypeRaw) ?? LeaveDayType::FullDay;

            try {
                $breakdown = $this->service->preview($employee, $start, $end, $dayType);
                $peerAbsenceCount = $this->teamCapacityQuery->countOverlappingPeers($employee, $start, $end);
            } catch (BackdatedLeaveRequestException) {
                $errorKey = 'my.leave_requests.preview.backdated';
            } catch (MultiDayHalfDayException) {
                $errorKey = 'my.leave_requests.preview.half_day_multi_day';
            } catch (BlackoutPeriodViolationException) {
                $errorKey = 'my.leave_requests.preview.blackout_period';
            } catch (\ValueError) {
                $errorKey = 'my.leave_requests.preview.unknown_federal_state';
            }
        }

        return $this->render('my/leave_request/_preview.html.twig', [
            'breakdown' => $breakdown,
            'errorKey' => $errorKey,
            'peerAbsenceCount' => $peerAbsenceCount,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $employee = $this->getEmployeeOrRedirect();
        if ($employee instanceof Response) {
            return $employee;
        }

        $leaveRequest = $this->leaveRequestRepository->find($id);
        if (null === $leaveRequest || $leaveRequest->getEmployee() !== $employee) {
            throw $this->createNotFoundException();
        }

        return $this->render('my/leave_request/show.html.twig', [
            'leaveRequest' => $leaveRequest,
            'canRequestCancel' => $this->canRequestCancel($leaveRequest),
            'auditEntries' => $this->auditRepository->findByLeaveRequest($leaveRequest),
        ]);
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request): Response
    {
        $employee = $this->getEmployeeOrRedirect();
        if ($employee instanceof Response) {
            return $employee;
        }

        $leaveRequest = $this->leaveRequestRepository->find($id);
        if (null === $leaveRequest || $leaveRequest->getEmployee() !== $employee) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('cancel-leave-request-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->approvalWorkflow->cancelDirect($leaveRequest, $employee);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('my.leave_requests.flash.cancelled'));
        } catch (InvalidTransitionException) {
            $this->addFlash('error', $this->translator->trans('my.leave_requests.error.cancel_not_allowed'));
        }

        return $this->redirectToRoute('app_my_leave_request_show', ['id' => $id]);
    }

    #[Route('/{id}/request-cancel', name: 'request_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function requestCancel(int $id, Request $request): Response
    {
        $employee = $this->getEmployeeOrRedirect();
        if ($employee instanceof Response) {
            return $employee;
        }

        $leaveRequest = $this->leaveRequestRepository->find($id);
        if (null === $leaveRequest || $leaveRequest->getEmployee() !== $employee) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('request-cancel-leave-request-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->approvalWorkflow->requestCancel($leaveRequest, $employee);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('my.leave_requests.flash.cancel_requested'));
        } catch (CancellationNotAllowedException) {
            $this->addFlash('error', $this->translator->trans('my.leave_requests.error.request_cancel_too_late'));
        } catch (InvalidTransitionException) {
            $this->addFlash('error', $this->translator->trans('my.leave_requests.error.request_cancel_not_allowed'));
        }

        return $this->redirectToRoute('app_my_leave_request_show', ['id' => $id]);
    }

    private function canRequestCancel(\App\Domain\Entity\LeaveRequest $leaveRequest): bool
    {
        if (LeaveRequestStatus::Approved !== $leaveRequest->getStatus()) {
            return false;
        }

        return $leaveRequest->getStartDate() > $this->clock->now()->setTime(0, 0);
    }

    private function getEmployeeOrRedirect(): Employee|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $employee = $this->employeeRepository->findOneByUser($user);
        if (null === $employee) {
            $this->addFlash('warning', $this->translator->trans('my.leave_requests.error.no_employee_record'));

            return $this->redirectToRoute('app_profile');
        }

        return $employee;
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        if ('' === $raw) {
            return null;
        }

        foreach (['d.m.Y', 'Y-m-d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $raw);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed;
            }
        }

        return null;
    }
}
