<?php

/**
 * Learniq Submission Return Grade Entries
 *
 * When a marked Submission is returned, every learner on it gets a concept
 * GradeEntry for the assignment's plan component, with the proposed grade as
 * its value and the teacher who returned it as grader. Returning it again
 * (after a resubmission) updates those concept entries; a published entry is
 * left alone, as it is revised on the grade itself.
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
 * @spec openspec/specs/assignments/spec.md#requirement-returning-a-marked-submission-writes-a-grade-entry-for-every-learner-on-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Submission `return` -> one concept GradeEntry per learner.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/assignments/spec.md#requirement-returning-a-marked-submission-writes-a-grade-entry-for-every-learner-on-it
 */
class SubmissionReturnGradeEntries implements IEventListener {

	private const REGISTER = 'learniq';

	private const SOURCE_KIND = 'assignment-submission';

	/**
	 * Constructor.
	 *
	 * @param ObjectService          $objectService OpenRegister object access.
	 * @param IUserSession           $userSession   The teacher who returned the submission.
	 * @param ListenerSchemaResolver $schemas       Resolves the event's register and schema to slugs.
	 * @param LoggerInterface        $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly ListenerSchemaResolver $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-returning-a-marked-submission-writes-a-grade-entry-for-every-learner-on-it
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false
			|| $event->getAction() !== 'return'
			|| $this->schemas->eventRegister(event: $event) !== self::REGISTER
			|| $this->schemas->eventSchema(event: $event) !== 'submission'
		) {
			return;
		}

		$submission = $event->getObject()->jsonSerialize();
		$value = ($submission['proposedGrade'] ?? null);
		if ($value === null || $value === '') {
			return;
		}

		try {
			$this->writeEntries(submission: $submission);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[SubmissionReturnGradeEntries] No grade entries for submission {id}: {msg}',
				['id' => ($submission['id'] ?? ''), 'msg' => $exception->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Create or update the concept entry of every learner on the submission.
	 *
	 * @param array<string, mixed> $submission The returned submission.
	 *
	 * @return void
	 */
	private function writeEntries(array $submission): void {
		$plan = $this->gradePlan(assignmentId: (string)($submission['assignmentId'] ?? ''));
		if ($plan === null) {
			return;
		}

		$submissionId = (string)($submission['id'] ?? ($submission['uuid'] ?? ''));
		$existing = $this->existingEntries(submissionId: $submissionId);
		$base = [
			'curriculumPlanId' => $plan['curriculumPlanId'],
			'componentId'      => $plan['componentId'],
			'courseId'         => $plan['courseId'],
			'gradeScaleId'     => $plan['gradeScaleId'],
			'sourceKind'       => self::SOURCE_KIND,
			'submissionId'     => $submissionId,
			'value'            => $submission['proposedGrade'],
			'grader'           => (string)$this->userSession->getUser()?->getUID(),
			'gradedAt'         => (new DateTimeImmutable())->format(\DATE_ATOM),
			'tenant_id'        => (string)($submission['tenant_id'] ?? ''),
		];

		$learnerIds = array_values(array_unique(array_filter(($submission['learnerIds'] ?? []), static fn ($id): bool => is_string($id) === true && $id !== '')));
		foreach ($learnerIds as $learnerId) {
			$entry = ($existing[$learnerId] ?? null);
			if ($entry !== null && ($entry['lifecycle'] ?? 'concept') !== 'concept') {
				continue;
			}

			$object = array_merge(($entry ?? ['lifecycle' => 'concept']), $base, ['learnerId' => $learnerId]);
			$this->objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: 'grade-entry',
				uuid: ($entry['id'] ?? null)
			);
		}
	}//end writeEntries()

	/**
	 * The plan, component, course and grade scale of an assignment, or null
	 * when the assignment does not name a plan component.
	 *
	 * @param string $assignmentId The assignment's uuid.
	 *
	 * @return array{curriculumPlanId: string, componentId: string, courseId: string, gradeScaleId: string}|null
	 */
	private function gradePlan(string $assignmentId): ?array {
		$assignment = $this->read(schema: 'assignment', id: $assignmentId);
		$componentId = (string)($assignment['curriculumPlanComponentId'] ?? '');
		$courseId = (string)($assignment['courseId'] ?? '');
		if ($componentId === '' || $courseId === '') {
			return null;
		}

		$planId = (string)($this->read(schema: 'course', id: $courseId)['curriculumPlanId'] ?? '');
		if ($planId === '') {
			return null;
		}

		$plan = $this->read(schema: 'curriculum-plan', id: $planId);
		if ($plan === []) {
			return null;
		}

		return [
			'curriculumPlanId' => $planId,
			'componentId'      => $componentId,
			'courseId'         => $courseId,
			'gradeScaleId'     => (string)($plan['gradeScaleId'] ?? ''),
		];
	}//end gradePlan()

	/**
	 * The entries this submission already produced, by learner.
	 *
	 * @param string $submissionId The submission's uuid.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function existingEntries(string $submissionId): array {
		$rows = $this->objectService->findAll(
			[
				'filters' => [
					'register'     => self::REGISTER,
					'schema'       => 'grade-entry',
					'submissionId' => $submissionId,
					'sourceKind'   => self::SOURCE_KIND,
				],
			]
		);

		$byLearner = [];
		foreach ($rows as $row) {
			$data = (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) ? $row->jsonSerialize() : (array)$row;
			if (is_string($data['learnerId'] ?? null) === true) {
				$byLearner[$data['learnerId']] = $data;
			}
		}

		return $byLearner;
	}//end existingEntries()

	/**
	 * One object as an array, or [] when it is not found.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object's uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function read(string $schema, string $id): array {
		if ($id === '') {
			return [];
		}

		$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);

		return $entity === null ? [] : $entity->jsonSerialize();
	}//end read()
}//end class
