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

namespace App\Application\Ical;

use App\Domain\Entity\LeaveRequest;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\ValueObject\Date;
use Eluceo\iCal\Domain\ValueObject\MultiDay;
use Eluceo\iCal\Domain\ValueObject\SingleDay;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;

/**
 * Renders a list of LeaveRequest entities into an iCalendar string.
 *
 * Personal feeds expose the absence type only ("Urlaub", "Krankheit");
 * team feeds prefix with the employee name so subscribers see who is
 * away. Both flavors use the LeaveRequest id as the iCal UID so calendar
 * clients can re-sync without producing duplicates after later edits.
 *
 * eluceo/ical 2.x's MultiDay endpoint is inclusive on both ends —
 * mirroring LeaveRequest's startDate/endDate semantics — so no off-by-one
 * adjustment is needed.
 */
final readonly class IcalFeedBuilder
{
    private const string PRODUCT_ID = '-//LeaveFlow//Calendar Feed//DE';

    /**
     * @param list<LeaveRequest> $requests
     */
    public function buildPersonalFeed(array $requests): string
    {
        return $this->build($requests, includeEmployeeName: false);
    }

    /**
     * @param list<LeaveRequest> $requests
     */
    public function buildTeamFeed(array $requests): string
    {
        return $this->build($requests, includeEmployeeName: true);
    }

    /**
     * @param list<LeaveRequest> $requests
     */
    private function build(array $requests, bool $includeEmployeeName): string
    {
        $calendar = new Calendar();
        $calendar->setProductIdentifier(self::PRODUCT_ID);

        foreach ($requests as $request) {
            $calendar->addEvent($this->makeEvent($request, $includeEmployeeName));
        }

        return (string) (new CalendarFactory())->createCalendar($calendar);
    }

    private function makeEvent(LeaveRequest $request, bool $includeEmployeeName): Event
    {
        $absenceTypeName = $request->getAbsenceType()->getName();
        $summary = $includeEmployeeName
            ? \sprintf('%s – %s', $request->getEmployee()->getFullName(), $absenceTypeName)
            : $absenceTypeName;

        $startDate = $request->getStartDate();
        $endDate = $request->getEndDate();

        $occurrence = $startDate->format('Y-m-d') === $endDate->format('Y-m-d')
            ? new SingleDay(new Date($startDate))
            : new MultiDay(new Date($startDate), new Date($endDate));

        // Stable UID per request so re-fetching after edits doesn't duplicate
        // the event in subscribed clients. Falls back to a random UID for
        // not-yet-persisted requests (relevant in unit tests only).
        $requestId = $request->getId();
        $uid = null !== $requestId
            ? new UniqueIdentifier(\sprintf('leave-request-%d@leaveflow', $requestId))
            : UniqueIdentifier::createRandom();

        $event = new Event($uid);
        $event->setSummary($summary);
        $event->setOccurrence($occurrence);
        $event->setDescription($this->buildDescription($request));

        return $event;
    }

    private function buildDescription(LeaveRequest $request): string
    {
        $hours = $request->getTotalHours();
        if ($hours <= 0.0) {
            return '';
        }

        return \sprintf('%s h', number_format($hours, 1, ',', '.'));
    }
}
