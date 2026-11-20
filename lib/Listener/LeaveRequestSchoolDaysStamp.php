<?php

/**
 * Leave request school days and deadline stamp
 *
 * Every write of a `leave-request` gets its `schoolDays` from the server,
 * counted over the range with the school's holidays left out, whatever the
 * client sent: the count decides whether the school may allow or refuse the
 * request (LeaveDecisionGuard), so a form must not be able to lower it. A new
 * request also gets `decideBy`, the date the school answers by; an update
 * keeps the stored one. A range whose last day lies before its first, or a
 * date that is not a date, is refused.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Service\Leave\LeaveCalendar;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Stamps the school days and the decision deadline of a leave request.
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/attendance/spec.md#requirement-a-leave-request-is-asked-in-advance-and-decided-by-the-head-of-the-school
 */
class LeaveRequestSchoolDaysStamp implements IEventListener {

	public const SCHEMA = 'leave-request';

	public const INVALID_RANGE = 'De laatste verlofdag ligt vóór de eerste, of een datum is geen datum.';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Entity schema id to slug.
	 * @param LeaveCalendar                   $calendar       School days and the deadline.
	 * @param ITimeFactory                    $timeFactory    The server's clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LeaveCalendar $calendar,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Stamp a leave request on create and update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		if ($event->isPropagationStopped() === true || $this->isLeaveRequest(entity: $entity) === false) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$schoolDays = $this->calendar->schoolDays(
			from: (string)($payload['dateFrom'] ?? ''),
			to: (string)($payload['dateTo'] ?? '')
		);
		if ($schoolDays === null) {
			$event->setErrors(['reason' => 'leave-range-invalid', 'message' => self::INVALID_RANGE]);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['schoolDays' => $schoolDays, 'decideBy' => $this->decideBy(event: $event)]
			)
		);
	}//end handle()

	/**
	 * The stored deadline on an update, else the one from today.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write.
	 *
	 * @return string|null The date.
	 */
	private function decideBy(ObjectCreatingEvent|ObjectUpdatingEvent $event): ?string {
		if ($event instanceof ObjectUpdatingEvent === true) {
			$stored = ($event->getOldObject()?->getObject()['decideBy'] ?? null);
			if (is_string($stored) === true && $stored !== '') {
				return $stored;
			}
		}

		return $this->calendar->decideBy(askedOn: gmdate('Y-m-d', $this->timeFactory->getTime()));
	}//end decideBy()

	/**
	 * Whether the object is a leave request.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return bool
	 */
	private function isLeaveRequest(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::SCHEMA;
		} catch (Throwable $exception) {
			unset($exception);
			return false;
		}
	}//end isLeaveRequest()

	/**
	 * The object being written: the new state on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()
}//end class
