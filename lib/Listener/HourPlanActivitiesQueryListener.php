<?php

/**
 * Learniq Hour Plan Activities Query Listener
 *
 * Answers {@see \OCA\Learniq\Event\HourPlanActivitiesQueryEvent} with the
 * activities {@see \OCA\Learniq\Service\HourPlanActivityService} derives, or
 * refuses a school year it cannot read.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Event\HourPlanActivitiesQueryEvent;
use OCA\Learniq\Service\HourPlanActivityService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Answers the in-process teaching activities query.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */
class HourPlanActivitiesQueryListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param HourPlanActivityService $activities Derives the activities.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly HourPlanActivityService $activities,
	) {
	}//end __construct()

	/**
	 * Answer the query.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function handle(Event $event): void {
		if ($event instanceof HourPlanActivitiesQueryEvent === false || $event->isHandled() === true) {
			return;
		}

		$year = $event->getAcademicYear();
		if ($this->activities->intakeYearOf(academicYear: $year, programmeYear: 1) === null) {
			$event->setError('The school year must read like 2026-2027.');
			return;
		}

		$event->setResult($this->activities->forYear(academicYear: $year));
	}//end handle()
}//end class
