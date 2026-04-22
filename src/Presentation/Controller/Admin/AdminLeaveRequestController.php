<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Repository\LeaveRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only admin view of all leave requests in the company.
 *
 * Phase 5 surfaces the list and the per-request breakdown so admins can sanity-
 * check the data produced by employees. Approve/reject actions land in Phase 6
 * via the Symfony Workflow state machine.
 */
#[Route('/admin/leave-requests', name: 'app_admin_leave_request_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminLeaveRequestController extends AbstractController
{
    public function __construct(
        private readonly LeaveRequestRepository $repository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->requireCompany();

        $statusFilter = $request->query->get('status');
        $status = \is_string($statusFilter) ? LeaveRequestStatus::tryFrom($statusFilter) : null;

        $qb = $this->repository->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->andWhere('e.company = :company')
            ->setParameter('company', $company)
            ->orderBy('r.requestedAt', 'DESC');

        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status->value);
        }

        return $this->render('admin/leave_requests/index.html.twig', [
            'requests' => $qb->getQuery()->getResult(),
            'status_filter' => $status,
            'statuses' => LeaveRequestStatus::cases(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $company = $this->requireCompany();

        $leaveRequest = $this->repository->find($id);
        if (null === $leaveRequest || $leaveRequest->getEmployee()->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/leave_requests/show.html.twig', [
            'leaveRequest' => $leaveRequest,
        ]);
    }

    private function requireCompany(): Company
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user->getCompany();
    }
}
