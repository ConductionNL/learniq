<?php

/**
 * Learniq exam objection decision handler
 *
 * When the exam committee decides an objection and changes the result, the
 * contested GradeEntry gets the new value through the grade's own correction
 * path: `revise` (published → revised), the new value saved, then `republish`
 * (revised → published). The lifecycle engine runs the publish guards and
 * notifications as for any other correction, and the entry's audit trail keeps
 * the old value. An upheld result changes nothing.
 *
 * ADR-031 legitimate exception: a cross-schema write bridge, the same shape as
 * ExemptionGrantHandler (ExemptionCase granted → GradeEntry).
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/exam-board/spec.md#requirement-a-student-objects-against-a-result-and-the-committee-decides-within-its-term
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Lifecycle\ExamObjectionDecisionGuard;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Writes a changed result through the grade's correction path.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */
class ExamObjectionDecisionHandler implements IEventListener {

	/**
	 * The register both schemas live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * The objection schema.
	 *
	 * @var string
	 */
	private const OBJECTION_SCHEMA = 'exam-objection';

	/**
	 * The grade schema.
	 *
	 * @var string
	 */
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';

	/**
	 * Constructor.
	 *
	 * @param ObjectService                   $objectService    Reads and saves the grade entry and the objection.
	 * @param TransitionEngine                $transitionEngine Drives the grade's revise and republish.
	 * @param ListenerSchemaResolverInterface $schemas          Reads the event's register and schema.
	 * @param LoggerInterface                 $logger           Logs a result that could not be changed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly ListenerSchemaResolverInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * React to an objection reaching `decided` with a changed result.
	 *
	 * @param Event $event The transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false
			|| $this->schemas->eventRegister(event: $event) !== self::REGISTER
			|| $this->schemas->eventSchema(event: $event) !== self::OBJECTION_SCHEMA
			|| $event->getTo() !== 'decided'
		) {
			return;
		}

		$objection = $event->getObject()->jsonSerialize();
		if (($objection['decisionOutcome'] ?? null) !== ExamObjectionDecisionGuard::OUTCOME_CHANGED
			|| is_numeric($objection['newValue'] ?? null) === false
		) {
			return;
		}

		$this->changeResult(objection: $objection);
	}//end handle()

	/**
	 * Give the contested grade entry its new value and link it back.
	 *
	 * @param array<string, mixed> $objection The decided objection.
	 *
	 * @return void
	 */
	private function changeResult(array $objection): void {
		$objectionId  = (string) ($objection['id'] ?? ($objection['uuid'] ?? ''));
		$gradeEntryId = (string) ($objection['gradeEntryId'] ?? '');
		$entity       = null;
		if ($gradeEntryId !== '') {
			$entity = $this->objectService->find(
				id: $gradeEntryId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::GRADE_ENTRY_SCHEMA)
			);
		}

		if ($entity === null) {
			$this->logger->warning(
				'[ExamObjectionDecisionHandler] ExamObjection {id}: the contested grade entry {entry} was not found; the result is unchanged.',
				['id' => $objectionId, 'entry' => $gradeEntryId]
			);
			return;
		}

		$entry = $entity->jsonSerialize();
		if (($entry['lifecycle'] ?? null) === 'published') {
			$this->transitionEngine->transition($gradeEntryId, 'revise');
			$entry = $this->objectService->find(
				id: $gradeEntryId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::GRADE_ENTRY_SCHEMA)
			)?->jsonSerialize() ?? $entry;
		}

		$this->objectService->saveObject(
			object: array_merge($entry, ['value' => (float) $objection['newValue'], 'examObjectionId' => $objectionId]),
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::GRADE_ENTRY_SCHEMA))
		);
		$this->transitionEngine->transition($gradeEntryId, 'republish');

		$this->objectService->saveObject(
			object: array_merge($objection, ['resultingGradeEntryId' => $gradeEntryId]),
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::OBJECTION_SCHEMA))
		);
	}//end changeResult()
}//end class
