<?php

/**
 * Learniq Portfolio Grade Emit Handler
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * IEventListener for Portfolio lifecycle → `graded` (the OR
 * ObjectTransitionedEvent with register=learniq, schema=portfolio,
 * to=graded). Bridges a graded course-bound portfolio into the `grading`
 * spec's pipeline by emitting exactly one `concept` GradeEntry
 * (sourceKind: portfolio) from the teacher-entered Portfolio.gradeValue, then
 * back-links Portfolio.gradeEntryId — matching
 * GradeRollupHandler::handleAssessmentResultGraded()'s existing cross-schema
 * write-bridge shape exactly. Portfolio computes no final grade itself; the
 * emitted GradeEntry is consumed by grading's existing roll-up
 * (GradeRollupHandler::handleGradeEntryPublished()) once a teacher publishes
 * it via the GradebookView.
 *
 * Idempotent: skips emission when Portfolio.gradeEntryId is already set, so a
 * re-processed/redelivered `graded` transition never creates a duplicate
 * GradeEntry.
 *
 * ADR-031 legitimate exception: cross-schema event-to-object-write bridge
 * (Portfolio → CurriculumPlan lookup → GradeEntry write) that cannot be
 * expressed as a schema declaration.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 * @spec openspec/specs/eportfolio/spec.md#requirement-a-graded-course-bound-portfolio-flows-through-the-existing-gradeentry-pipeline-not-a-parallel-one
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Service\ObjectRowReader;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges Portfolio.graded → concept GradeEntry creation.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */
class PortfolioGradeEmitHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const PORTFOLIO_SCHEMA = 'portfolio';
	private const CURRICULUM_SCHEMA = 'curriculum-plan';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';

	/**
	 * Reads single learniq objects by id.
	 *
	 * @var ObjectRowReader
	 */
	private readonly ObjectRowReader $rows;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ListenerSchemaResolverInterface $schemas Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolverInterface $schemas,
	) {
		$this->rows = new ObjectRowReader(objectService: $objectService);
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($this->schemas->eventSchema(event: $event) !== self::PORTFOLIO_SCHEMA) {
			return;
		}

		if ($event->getTo() !== 'graded') {
			return;
		}

		$this->emitGradeEntry(event: $event);

	}//end handle()

	/**
	 * Emit the concept GradeEntry bridged from a graded course-bound Portfolio.
	 *
	 * @param ObjectTransitionedEvent $event The graded transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/eportfolio/spec.md#scenario-transitioning-a-course-bound-portfolio-to-graded-emits-a-concept-gradeentry
	 * @spec openspec/specs/eportfolio/spec.md#scenario-re-triggering-the-graded-transition-does-not-create-a-duplicate-gradeentry
	 */
	private function emitGradeEntry(ObjectTransitionedEvent $event): void {
		$portfolio = $event->getObject()->jsonSerialize();
		$portfolioId = $portfolio['id'] ?? ($portfolio['uuid'] ?? '');

		if ($this->mayEmit(portfolio: $portfolio, portfolioId: $portfolioId) === false) {
			return;
		}

		$saved = $this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::GRADE_ENTRY_SCHEMA,
			object: $this->gradeEntryFor(portfolio: $portfolio, portfolioId: $portfolioId, grader: $event->getUserId() ?? '')
		);

		$gradeEntryId = $saved->jsonSerialize()['id'] ?? null;

		if ($gradeEntryId === null) {
			return;
		}

		$this->linkGradeEntry(portfolio: $portfolio, portfolioId: $portfolioId, gradeEntryId: $gradeEntryId);

	}//end emitGradeEntry()

	/**
	 * Whether a GradeEntry may be emitted: none exists yet and the required fields are present.
	 *
	 * @param array<string, mixed> $portfolio   The graded Portfolio.
	 * @param mixed                $portfolioId The Portfolio's id, for the log.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/eportfolio/spec.md#scenario-re-triggering-the-graded-transition-does-not-create-a-duplicate-gradeentry
	 */
	private function mayEmit(array $portfolio, mixed $portfolioId): bool {
		$existingId = $portfolio['gradeEntryId'] ?? null;

		// Idempotency: never create a duplicate when gradeEntryId is already set.
		if ($existingId !== null && $existingId !== '') {
			$this->logger->info(
				'[PortfolioGradeEmitHandler] Portfolio {id} already has gradeEntryId {geid}; skipping.',
				['id' => $portfolioId, 'geid' => $existingId]
			);
			return false;
		}

		if (($portfolio['learnerId'] ?? '') === ''
			|| ($portfolio['curriculumPlanId'] ?? '') === ''
			|| ($portfolio['curriculumPlanComponentId'] ?? '') === ''
			|| ($portfolio['gradeValue'] ?? null) === null
		) {
			$this->logger->warning(
				'[PortfolioGradeEmitHandler] Graded Portfolio {id} missing learnerId/curriculumPlanId/'
				. 'curriculumPlanComponentId/gradeValue — cannot emit GradeEntry.',
				['id' => $portfolioId]
			);
			return false;
		}

		return true;

	}//end mayEmit()

	/**
	 * The concept GradeEntry a graded Portfolio bridges to.
	 *
	 * @param array<string, mixed> $portfolio   The graded Portfolio.
	 * @param mixed                $portfolioId The Portfolio's id.
	 * @param string               $grader      The user who graded it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/eportfolio/spec.md#scenario-transitioning-a-course-bound-portfolio-to-graded-emits-a-concept-gradeentry
	 */
	private function gradeEntryFor(array $portfolio, mixed $portfolioId, string $grader): array {
		$curriculumId = $portfolio['curriculumPlanId'];
		$curriculumPlan = $this->loadObject(schema: self::CURRICULUM_SCHEMA, id: $curriculumId);

		return [
			'learnerId' => $portfolio['learnerId'],
			'curriculumPlanId' => $curriculumId,
			'componentId' => $portfolio['curriculumPlanComponentId'],
			'sourceKind' => 'portfolio',
			'portfolioId' => $portfolioId,
			'value' => (float)$portfolio['gradeValue'],
			'gradeScaleId' => $curriculumPlan['gradeScaleId'] ?? '',
			'grader' => $grader,
			'gradedAt' => (new DateTimeImmutable())->format(\DATE_ATOM),
			'tenant_id' => $portfolio['tenant_id'] ?? '',
			'lifecycle' => 'concept',
		];

	}//end gradeEntryFor()

	/**
	 * Back-link the GradeEntry onto the Portfolio.
	 *
	 * @param array<string, mixed> $portfolio    The graded Portfolio.
	 * @param mixed                $portfolioId  The Portfolio's id, for the log.
	 * @param mixed                $gradeEntryId The created GradeEntry's id.
	 *
	 * @return void
	 */
	private function linkGradeEntry(array $portfolio, mixed $portfolioId, mixed $gradeEntryId): void {
		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::PORTFOLIO_SCHEMA,
			object: array_merge($portfolio, ['gradeEntryId' => $gradeEntryId])
		);

		$this->logger->info(
			'[PortfolioGradeEmitHandler] Portfolio {id} graded → GradeEntry {geid} created for learner {learner}, '
			. 'component {component}.',
			[
				'id' => $portfolioId,
				'geid' => $gradeEntryId,
				'learner' => $portfolio['learnerId'],
				'component' => $portfolio['curriculumPlanComponentId'],
			]
		);

	}//end linkGradeEntry()

	/**
	 * Load a single OpenRegister object by id.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Object UUID.
	 *
	 * @return array<string,mixed>|null The object data, or null when not found.
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
	 */
	private function loadObject(string $schema, string $id): ?array {
		return $this->rows->load(schema: $schema, id: $id);
	}//end loadObject()
}//end class
