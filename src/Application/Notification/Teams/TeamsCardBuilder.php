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

namespace App\Application\Notification\Teams;

use App\Domain\Entity\LeaveRequest;

/**
 * Builds Microsoft Adaptive Card payloads for leave-request events.
 *
 * Cards follow the Adaptive Card v1.4 schema and are formatted for the
 * Teams incoming webhook format (wrapped in an Attachment envelope).
 *
 * @see https://adaptivecards.io/schemas/adaptive-card.json
 */
final class TeamsCardBuilder
{
    /**
     * Card posted to the configured channel when a new request is submitted
     * and requires manager approval.
     *
     * @return array<string, mixed>
     */
    public function buildPendingRequestCard(LeaveRequest $request): array
    {
        $employee = $request->getEmployee();
        $type = $request->getAbsenceType()->getName();
        $start = $request->getStartDate()->format('d.m.Y');
        $end = $request->getEndDate()->format('d.m.Y');
        $dateRange = $start === $end ? $start : "{$start} – {$end}";

        return $this->wrapCard([
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => [
                [
                    'type' => 'TextBlock',
                    'text' => 'Neuer Urlaubsantrag',
                    'weight' => 'Bolder',
                    'size' => 'Medium',
                ],
                [
                    'type' => 'FactSet',
                    'facts' => [
                        ['title' => 'Mitarbeiter', 'value' => $employee->getFullName()],
                        ['title' => 'Art', 'value' => $type],
                        ['title' => 'Zeitraum', 'value' => $dateRange],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Card posted when a request has been approved or rejected.
     *
     * @return array<string, mixed>
     */
    public function buildDecisionCard(LeaveRequest $request, string $decision, string $decidedBy): array
    {
        $employee = $request->getEmployee();
        $type = $request->getAbsenceType()->getName();
        $start = $request->getStartDate()->format('d.m.Y');
        $end = $request->getEndDate()->format('d.m.Y');
        $dateRange = $start === $end ? $start : "{$start} – {$end}";

        $isApproved = 'approved' === $decision;
        $statusText = $isApproved ? 'Genehmigt' : 'Abgelehnt';
        $statusColor = $isApproved ? 'Good' : 'Attention';

        $facts = [
            ['title' => 'Mitarbeiter', 'value' => $employee->getFullName()],
            ['title' => 'Art', 'value' => $type],
            ['title' => 'Zeitraum', 'value' => $dateRange],
        ];

        if ('' !== $decidedBy) {
            $facts[] = ['title' => 'Entschieden von', 'value' => $decidedBy];
        }

        return $this->wrapCard([
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => [
                [
                    'type' => 'TextBlock',
                    'text' => "Urlaubsantrag {$statusText}",
                    'weight' => 'Bolder',
                    'size' => 'Medium',
                    'color' => $statusColor,
                ],
                [
                    'type' => 'FactSet',
                    'facts' => $facts,
                ],
            ],
        ]);
    }

    /**
     * Wraps an Adaptive Card in the Teams incoming webhook envelope format.
     *
     * @param array<string, mixed> $adaptiveCard
     *
     * @return array<string, mixed>
     */
    private function wrapCard(array $adaptiveCard): array
    {
        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => $adaptiveCard,
                ],
            ],
        ];
    }
}
