<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Approval;

use App\Application\Approval\ApprovalWorkflow;
use App\Application\Approval\CancellationNotAllowedException;
use App\Application\Approval\InvalidTransitionException;
use App\Application\Approval\RejectionReasonRequiredException;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * Unit tests for the ApprovalWorkflow service.
 *
 * The service wraps Symfony's state_machine and enforces domain-level rules
 * that the raw workflow cannot express (rejection reason non-empty, future-only
 * rule for request_cancel). Tests construct a real StateMachine with the same
 * transitions declared in config/packages/workflow.yaml — they cover both the
 * wrapper logic and the workflow config contract.
 */
#[CoversClass(ApprovalWorkflow::class)]
#[CoversClass(InvalidTransitionException::class)]
#[CoversClass(RejectionReasonRequiredException::class)]
#[CoversClass(CancellationNotAllowedException::class)]
final class ApprovalWorkflowTest extends TestCase
{
    private Company $acme;
    private Employee $employee;
    private Employee $manager;
    private AbsenceType $urlaub;
    private AbsenceType $krankheit;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $hq = new Location($this->acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->employee = new Employee(
            company: $this->acme,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-0001',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->manager = new Employee(
            company: $this->acme,
            fullName: 'Max Mustermann',
            employeeNumber: 'EMP-0002',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2018-01-01'),
        );
        $this->urlaub = new AbsenceType(
            company: $this->acme,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
        $this->krankheit = new AbsenceType(
            company: $this->acme,
            name: 'Krankheit',
            deductsFromLeave: false,
            requiresApproval: false,
            color: '#EF4444',
        );
    }

    // -----------------------------------------------------------------
    // approve
    // -----------------------------------------------------------------

    #[Test]
    public function approvePendingRequestTransitionsToApproved(): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildPendingRequest();

        $workflow->approve($request, $this->manager);

        self::assertSame(LeaveRequestStatus::Approved, $request->getStatus());
    }

    #[Test]
    public function approveRejectsInvalidSourceState(): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildPendingRequest();
        $workflow->approve($request, $this->manager);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('approve');

        $workflow->approve($request, $this->manager);
    }

    // -----------------------------------------------------------------
    // reject
    // -----------------------------------------------------------------

    #[Test]
    public function rejectPendingRequestWithReasonTransitionsToRejected(): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildPendingRequest();

        $workflow->reject($request, $this->manager, 'Teambesetzung im Zeitraum nicht ausreichend');

        self::assertSame(LeaveRequestStatus::Rejected, $request->getStatus());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyReasonProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'single space' => [' '];
        yield 'tabs and newlines' => ["\t\n "];
    }

    #[Test]
    #[DataProvider('emptyReasonProvider')]
    public function rejectRequiresNonEmptyReason(string $reason): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildPendingRequest();

        $this->expectException(RejectionReasonRequiredException::class);

        $workflow->reject($request, $this->manager, $reason);
    }

    #[Test]
    public function rejectDoesNotMutateStatusWhenReasonIsMissing(): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildPendingRequest();

        try {
            $workflow->reject($request, $this->manager, '');
        } catch (RejectionReasonRequiredException) {
            // expected
        }

        self::assertSame(LeaveRequestStatus::Pending, $request->getStatus());
    }

    #[Test]
    public function rejectFromApprovedStateFails(): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildPendingRequest();
        $workflow->approve($request, $this->manager);

        $this->expectException(InvalidTransitionException::class);

        $workflow->reject($request, $this->manager, 'Zu spät');
    }

    // -----------------------------------------------------------------
    // cancelDirect — self-service cancellation for pending/recorded
    // -----------------------------------------------------------------

    #[Test]
    public function cancelDirectFromPendingTransitionsToCancelled(): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildPendingRequest();

        $workflow->cancelDirect($request, $this->employee);

        self::assertSame(LeaveRequestStatus::Cancelled, $request->getStatus());
    }

    #[Test]
    public function cancelDirectFromRecordedTransitionsToCancelled(): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildRecordedRequest();

        $workflow->cancelDirect($request, $this->employee);

        self::assertSame(LeaveRequestStatus::Cancelled, $request->getStatus());
    }

    #[Test]
    public function cancelDirectFromApprovedStateFails(): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildPendingRequest();
        $workflow->approve($request, $this->manager);

        $this->expectException(InvalidTransitionException::class);

        $workflow->cancelDirect($request, $this->employee);
    }

    // -----------------------------------------------------------------
    // requestCancel — approved → cancel_requested (future-only)
    // -----------------------------------------------------------------

    #[Test]
    public function requestCancelOnApprovedFutureRequestTransitionsToCancelRequested(): void
    {
        $clock = new MockClock('2026-07-01 09:00:00');
        $workflow = $this->buildApprovalWorkflow($clock);

        // Leave in the future — 2026-07-06 is after 2026-07-01.
        $request = $this->buildPendingRequest();
        $workflow->approve($request, $this->manager);

        $workflow->requestCancel($request, $this->employee);

        self::assertSame(LeaveRequestStatus::CancelRequested, $request->getStatus());
    }

    #[Test]
    public function requestCancelRefusesWhenLeaveAlreadyStarted(): void
    {
        $clock = new MockClock('2026-07-06 09:00:00');
        $workflow = $this->buildApprovalWorkflow($clock);

        // Leave starts 2026-07-06 and today is 2026-07-06 → already started.
        $request = $this->buildPendingRequest();
        $workflow->approve($request, $this->manager);

        $this->expectException(CancellationNotAllowedException::class);

        $workflow->requestCancel($request, $this->employee);
    }

    #[Test]
    public function requestCancelRefusesWhenLeaveIsInThePast(): void
    {
        $clock = new MockClock('2026-08-01 09:00:00');
        $workflow = $this->buildApprovalWorkflow($clock);

        $request = $this->buildPendingRequest();
        $workflow->approve($request, $this->manager);

        $this->expectException(CancellationNotAllowedException::class);

        $workflow->requestCancel($request, $this->employee);
    }

    #[Test]
    public function requestCancelFromPendingStateFails(): void
    {
        $clock = new MockClock('2026-07-01 09:00:00');
        $workflow = $this->buildApprovalWorkflow($clock);

        $request = $this->buildPendingRequest();

        $this->expectException(InvalidTransitionException::class);

        $workflow->requestCancel($request, $this->employee);
    }

    // -----------------------------------------------------------------
    // confirmCancel / denyCancel — manager decision on cancel request
    // -----------------------------------------------------------------

    #[Test]
    public function confirmCancelTransitionsCancelRequestedToCancelled(): void
    {
        $clock = new MockClock('2026-07-01 09:00:00');
        $workflow = $this->buildApprovalWorkflow($clock);

        $request = $this->buildPendingRequest();
        $workflow->approve($request, $this->manager);
        $workflow->requestCancel($request, $this->employee);

        $workflow->confirmCancel($request, $this->manager);

        self::assertSame(LeaveRequestStatus::Cancelled, $request->getStatus());
    }

    #[Test]
    public function denyCancelRevertsCancelRequestedToApproved(): void
    {
        $clock = new MockClock('2026-07-01 09:00:00');
        $workflow = $this->buildApprovalWorkflow($clock);

        $request = $this->buildPendingRequest();
        $workflow->approve($request, $this->manager);
        $workflow->requestCancel($request, $this->employee);

        $workflow->denyCancel($request, $this->manager);

        self::assertSame(LeaveRequestStatus::Approved, $request->getStatus());
    }

    #[Test]
    public function confirmCancelFromApprovedStateFails(): void
    {
        $workflow = $this->buildApprovalWorkflow();
        $request = $this->buildPendingRequest();
        $workflow->approve($request, $this->manager);

        $this->expectException(InvalidTransitionException::class);

        $workflow->confirmCancel($request, $this->manager);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function buildApprovalWorkflow(?MockClock $clock = null): ApprovalWorkflow
    {
        $definition = (new DefinitionBuilder())
            ->addPlaces(['pending', 'recorded', 'approved', 'rejected', 'cancelled', 'cancel_requested'])
            ->addTransition(new Transition('approve', 'pending', 'approved'))
            ->addTransition(new Transition('reject', 'pending', 'rejected'))
            ->addTransition(new Transition('cancel_pending', 'pending', 'cancelled'))
            ->addTransition(new Transition('cancel_recorded', 'recorded', 'cancelled'))
            ->addTransition(new Transition('request_cancel', 'approved', 'cancel_requested'))
            ->addTransition(new Transition('confirm_cancel', 'cancel_requested', 'cancelled'))
            ->addTransition(new Transition('deny_cancel', 'cancel_requested', 'approved'))
            ->build();

        $stateMachine = new StateMachine(
            $definition,
            new MethodMarkingStore(singleState: true, property: 'status'),
            name: 'leave_request_approval',
        );

        return new ApprovalWorkflow(
            $stateMachine,
            $clock ?? new MockClock('2026-05-01 09:00:00'),
        );
    }

    private function buildPendingRequest(): LeaveRequest
    {
        return new LeaveRequest(
            employee: $this->employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:30:00'),
        );
    }

    private function buildRecordedRequest(): LeaveRequest
    {
        return new LeaveRequest(
            employee: $this->employee,
            absenceType: $this->krankheit,
            startDate: new \DateTimeImmutable('2026-05-04'),
            endDate: new \DateTimeImmutable('2026-05-06'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-04 08:00:00'),
        );
    }
}
