<?php

declare(strict_types=1);

namespace App\Application\Notification\Slack;

use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;

/**
 * Builds Slack Block Kit payloads for leave-request events.
 *
 * @see https://api.slack.com/block-kit
 */
final readonly class SlackBlockBuilder
{
    private const string ACTION_APPROVE = 'approve';
    private const string ACTION_REJECT = 'reject';

    /**
     * Notification posted when a new leave request needs approval.
     * Includes Approve / Reject action buttons.
     *
     * @return list<array<string, mixed>>
     */
    public function buildPendingRequestBlocks(LeaveRequest $request): array
    {
        $id = (string) $request->getId();
        $employee = $request->getEmployee();
        $dateRange = $this->formatDateRange($request);

        return [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => 'Neuer Urlaubsantrag', 'emoji' => true],
            ],
            [
                'type' => 'section',
                'fields' => [
                    ['type' => 'mrkdwn', 'text' => '*Mitarbeiter*'."\n".$this->esc($employee->getFullName())],
                    ['type' => 'mrkdwn', 'text' => '*Art*'."\n".$this->esc($request->getAbsenceType()->getName())],
                    ['type' => 'mrkdwn', 'text' => '*Zeitraum*'."\n".$dateRange],
                    ['type' => 'mrkdwn', 'text' => '*Abteilung*'."\n".$this->esc($employee->getDepartment()?->getName() ?? '—')],
                ],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Genehmigen', 'emoji' => true],
                        'style' => 'primary',
                        'action_id' => self::ACTION_APPROVE.':'.$id,
                        'value' => $id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Ablehnen', 'emoji' => true],
                        'style' => 'danger',
                        'action_id' => self::ACTION_REJECT.':'.$id,
                        'value' => $id,
                    ],
                ],
            ],
        ];
    }

    /**
     * Replaces the pending card after a decision (no more action buttons).
     *
     * @return list<array<string, mixed>>
     */
    public function buildDecisionBlocks(LeaveRequest $request, string $decision, string $decidedBy): array
    {
        $isApproved = 'approved' === $decision;
        $statusEmoji = $isApproved ? ':white_check_mark:' : ':x:';
        $statusText = $isApproved ? 'Genehmigt' : 'Abgelehnt';
        $dateRange = $this->formatDateRange($request);
        $employee = $request->getEmployee();

        $fields = [
            ['type' => 'mrkdwn', 'text' => '*Mitarbeiter*'."\n".$this->esc($employee->getFullName())],
            ['type' => 'mrkdwn', 'text' => '*Art*'."\n".$this->esc($request->getAbsenceType()->getName())],
            ['type' => 'mrkdwn', 'text' => '*Zeitraum*'."\n".$dateRange],
        ];

        if ('' !== $decidedBy) {
            $fields[] = ['type' => 'mrkdwn', 'text' => '*Entschieden von*'."\n".$this->esc($decidedBy)];
        }

        return [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => "{$statusEmoji} Urlaubsantrag {$statusText}", 'emoji' => true],
            ],
            [
                'type' => 'section',
                'fields' => $fields,
            ],
        ];
    }

    /**
     * DM sent to the employee after a decision.
     *
     * @return list<array<string, mixed>>
     */
    public function buildEmployeeDmBlocks(LeaveRequest $request, string $decision): array
    {
        $isApproved = 'approved' === $decision;
        $statusEmoji = $isApproved ? ':white_check_mark:' : ':x:';
        $statusText = $isApproved ? 'genehmigt' : 'abgelehnt';
        $dateRange = $this->formatDateRange($request);

        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "{$statusEmoji} Dein Urlaubsantrag (".$this->esc($request->getAbsenceType()->getName()).", {$dateRange}) wurde *{$statusText}*.",
                ],
            ],
        ];
    }

    /**
     * Builds the modal view for the /urlaub slash command.
     *
     * @param list<array<string, mixed>> $absenceTypeOptions
     *
     * @return array<string, mixed>
     */
    public function buildLeaveRequestModal(array $absenceTypeOptions): array
    {
        return [
            'type' => 'modal',
            'callback_id' => 'leave_request_submit',
            'title' => ['type' => 'plain_text', 'text' => 'Urlaub beantragen', 'emoji' => true],
            'submit' => ['type' => 'plain_text', 'text' => 'Beantragen', 'emoji' => true],
            'close' => ['type' => 'plain_text', 'text' => 'Abbrechen', 'emoji' => true],
            'blocks' => [
                [
                    'type' => 'input',
                    'block_id' => 'absence_type',
                    'label' => ['type' => 'plain_text', 'text' => 'Art der Abwesenheit'],
                    'element' => [
                        'type' => 'static_select',
                        'action_id' => 'value',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Bitte wählen'],
                        'options' => $absenceTypeOptions,
                    ],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'start_date',
                    'label' => ['type' => 'plain_text', 'text' => 'Von'],
                    'element' => [
                        'type' => 'datepicker',
                        'action_id' => 'value',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Datum wählen'],
                    ],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'end_date',
                    'label' => ['type' => 'plain_text', 'text' => 'Bis'],
                    'element' => [
                        'type' => 'datepicker',
                        'action_id' => 'value',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Datum wählen'],
                    ],
                ],
            ],
        ];
    }

    private function formatDateRange(LeaveRequest $request): string
    {
        $start = $request->getStartDate()->format('d.m.Y');
        $end = $request->getEndDate()->format('d.m.Y');

        return $start === $end ? $start : "{$start} – {$end}";
    }

    /**
     * Escapes user-controlled strings for Slack mrkdwn to prevent
     * unintended mentions (<@U123>, <!channel>) or link injection.
     */
    private function esc(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
