<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-a-students-exam-progress-reads-in-words
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Service\Exam\ExamProgressRestamp;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Re-derives the exam progress of the students a change touches.
 *
 * A published result restamps its student; a new sitting, or a sitting that
 * is established or cancelled, restamps the students of its cohorts; an
 * approved, placed or withdrawn keuzedeel choice restamps its student.
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T4
 *
 * @template-implements IEventListener<Event>
 */
class ExamProgressCascade implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ExamProgressRestamp             $restamp       Writes the profile.
	 * @param ObjectService                   $objectService Reads the sitting's cohorts.
	 * @param ListenerSchemaResolverInterface $schemas       Resolves the schema.
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T4
	 */
	public function __construct(
		private readonly ExamProgressRestamp $restamp,
		private readonly ObjectService $objectService,
		private readonly ListenerSchemaResolverInterface $schemas,
	) {
	}//end __construct()

	/**
	 * React to a published result or a changed sitting.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T4
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent) {
			if ($this->schemas->eventRegister(event: $event) !== 'learniq') {
				return;
			}

			$schema = $this->schemas->eventSchema(event: $event);
			$object = $event->getObject()->jsonSerialize();
			if ($schema === 'grade-entry' && $event->getTo() === 'published') {
				$this->restamp->restamp(learnerUserId: (string) ($object['learnerId'] ?? ''));
			}

			if ($schema === 'keuzedeel-choice') {
				$this->restamp->restamp(learnerUserId: (string) ($object['learnerId'] ?? ''));
			}

			if ($schema === 'exam-sitting') {
				$this->restampCohorts(sitting: $object);
			}

			return;
		}

		if ($event instanceof ObjectCreatedEvent) {
			try {
				$entity = $event->getObject();
				if ($this->schemas->registerSlug(entity: $entity) !== 'learniq' || $this->schemas->schemaSlug(entity: $entity) !== 'exam-sitting') {
					return;
				}
			} catch (Throwable) {
				// Not knowing the schema is not knowing it is ours.
				return;
			}

			$this->restampCohorts(sitting: $entity->jsonSerialize());
		}
	}//end handle()

	/**
	 * Restamp every student of the sitting's cohorts.
	 *
	 * @param array<string, mixed> $sitting The sitting.
	 *
	 * @return void
	 */
	private function restampCohorts(array $sitting): void {
		$cohortIds = array_values(array_filter((array) ($sitting['cohortIds'] ?? []), 'is_string'));
		if ($cohortIds === []) {
			return;
		}

		$learners = [];
		$rows     = $this->objectService->findAll(
			config: ['filters' => ['register' => 'learniq', 'schema' => 'cohort'], 'ids' => $cohortIds, 'limit' => count($cohortIds)],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($rows as $row) {
			$cohort = $row;
			if (is_array($row) === false) {
				$cohort = (array) $row->jsonSerialize();
			}

			if (in_array((string) ($cohort['id'] ?? ($cohort['uuid'] ?? '')), $cohortIds, true) === false) {
				continue;
			}

			foreach ((array) ($cohort['learnerIds'] ?? []) as $learnerId) {
				if (is_string($learnerId) === true && $learnerId !== '') {
					$learners[$learnerId] = true;
				}
			}
		}

		foreach (array_keys($learners) as $learnerId) {
			$this->restamp->restamp(learnerUserId: (string) $learnerId);
		}
	}//end restampCohorts()
}//end class
