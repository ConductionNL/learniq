<?php

/**
 * Learniq Competency Attainment Rollup Handler
 *
 * IEventListener registered against BOTH OR's ObjectCreatedEvent and
 * ObjectTransitionedEvent (Application.php registers this one class for both
 * event classes — handle() branches on instanceof, matching the shape design.md's
 * File Structure section declares: exactly one new PHP listener file). Handles
 * two responsibilities:
 *
 * 1. WerkprocesAssessment creation → server-side competencyId resolution.
 *    Matches the newly-created assessment's werkprocesCode against a
 *    Competency.code under an sbb-kwalificatiedossier CompetencyFramework and,
 *    on a match, writes competencyId back onto the assessment. Never accepts
 *    competencyId as client input — including from the praktijkopleider
 *    portal action, whose field whitelist deliberately excludes it (design.md
 *    "WerkprocesAssessment generalization mechanics"). A miss leaves
 *    competencyId null and blocks nothing.
 *
 * 2. Competency-aligned evidence → CompetencyAttainment roll-up.
 *    Mirrors GradeRollupHandler/WerkprocesGradeEmitHandler's cross-schema
 *    write-bridge shape, but rolls up into CompetencyAttainment instead of
 *    FinalGrade/GradeEntry:
 *      - GradeEntry -> published (sourceKind: assignment-submission): resolves
 *        submissionId -> Submission.assignmentId -> Assignment.competencyIds.
 *      - GradeEntry -> published (sourceKind: assessment-result): resolves
 *        assessmentResultId -> AssessmentResult.assessmentId ->
 *        Assessment.competencyIds.
 *      - WerkprocesAssessment -> confirmed: uses the assessment's own
 *        generalized competencyId directly (no join needed).
 *    For each aligned competency, upserts one CompetencyAttainment row per
 *    (learnerId, competencyId), appending evidence ids idempotently and
 *    recomputing proficiencyLevelId (percentage-threshold mapping for
 *    GradeEntry evidence, direct beoordeling label mapping for
 *    WerkprocesAssessment evidence — design.md "Mastery roll-up mechanics").
 *
 * ADR-031 legitimate exception: cross-schema event-to-object-write bridge
 * (join through Submission/AssessmentResult/Assignment/Assessment/Competency/
 * CompetencyFramework lookups) that cannot be expressed as a schema
 * declaration alone. Never a TimedJob (ADR-022).
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
 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\CompetencyAttainmentWriter;
use OCA\Learniq\Service\CompetencyLevelResolver;
use OCA\Learniq\Service\GradeEvidenceRollup;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ObjectRowReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Resolves WerkprocesAssessment.competencyId at creation and rolls up
 * competency-aligned evidence into CompetencyAttainment on transition.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 */
class CompetencyAttainmentRollupHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';
	private const WERKPROCES_SCHEMA = 'werkproces-assessment';
	private const BPV_PLACEMENT_SCHEMA = 'bpv-placement';
	private const COMPETENCY_SCHEMA = 'competency';
	private const FRAMEWORK_SCHEMA = 'competency-framework';

	/**
	 * SBB kwalificatiedossier source authority used to scope werkprocesCode resolution.
	 */
	private const SBB_SOURCE_AUTHORITY = 'sbb-kwalificatiedossier';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's register/schema ids to slugs.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ObjectRowReader $reader Reads a single Learniq object by id.
	 * @param CompetencyAttainmentWriter $attainment Upserts CompetencyAttainment rows and appends evidence.
	 * @param CompetencyLevelResolver $levelResolver Resolves a proficiencyLevelId from a beoordeling label.
	 * @param GradeEvidenceRollup $gradeEvidence Rolls a published GradeEntry into the competencies it evidences.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
		private readonly ObjectRowReader $reader,
		private readonly CompetencyAttainmentWriter $attainment,
		private readonly CompetencyLevelResolver $levelResolver,
		private readonly GradeEvidenceRollup $gradeEvidence,
	) {
	}//end __construct()

	/**
	 * Handle an incoming OR event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === true) {
			$this->handleObjectCreated(event: $event);
			return;
		}

		if ($event instanceof ObjectTransitionedEvent === true) {
			$this->handleObjectTransitioned(event: $event);
		}

	}//end handle()

	/**
	 * Handle an ObjectCreatedEvent — resolves WerkprocesAssessment.competencyId.
	 *
	 * @param ObjectCreatedEvent $event The created-object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	private function handleObjectCreated(ObjectCreatedEvent $event): void {
		$entity = $event->getObject();

		if ($this->schemaResolver->registerSlug(entity: $entity) !== self::LEARNIQ_REGISTER
			|| $this->schemaResolver->schemaSlug(entity: $entity) !== self::WERKPROCES_SCHEMA
		) {
			return;
		}

		$this->resolveWerkprocesCompetencyId(data: $entity->jsonSerialize());

	}//end handleObjectCreated()

	/**
	 * Handle an ObjectTransitionedEvent — GradeEntry.published or WerkprocesAssessment.confirmed.
	 *
	 * @param ObjectTransitionedEvent $event The transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	private function handleObjectTransitioned(ObjectTransitionedEvent $event): void {
		if ($event->getRegister() !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($event->getSchema() === self::GRADE_ENTRY_SCHEMA && $event->getTo() === 'published') {
			$this->gradeEvidence->rollupPublishedGradeEntry(entry: $event->getObject()->jsonSerialize());
			return;
		}

		if ($event->getSchema() === self::WERKPROCES_SCHEMA && $event->getTo() === 'confirmed') {
			$this->handleWerkprocesConfirmed(assessment: $event->getObject()->jsonSerialize());
		}

	}//end handleObjectTransitioned()

	/**
	 * Resolve and persist WerkprocesAssessment.competencyId at creation time.
	 *
	 * Matches werkprocesCode against Competency.code scoped to an
	 * sbb-kwalificatiedossier CompetencyFramework. A miss leaves competencyId
	 * null and never blocks creation or the existing confirm/GradeEntry flow.
	 *
	 * @param array<string,mixed> $data The newly created WerkprocesAssessment data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	private function resolveWerkprocesCompetencyId(array $data): void {
		// Defensive no-op: competencyId is never client-settable (not in the
		// portal whitelist), but if it is already set (e.g. a re-fired event
		// on an already-resolved row) there is nothing to do.
		if (empty($data['competencyId']) === false) {
			return;
		}

		$werkprocesCode = $data['werkprocesCode'] ?? '';
		if ($werkprocesCode === '') {
			return;
		}

		$tenantId = $data['tenant_id'] ?? '';

		$competency = $this->findCompetencyByCode(code: $werkprocesCode, tenantId: $tenantId);
		if ($competency === null) {
			$this->logger->info(
				'[CompetencyAttainmentRollupHandler] WerkprocesAssessment {id}: werkprocesCode "{code}" has no '
				. 'matching Competency under an sbb-kwalificatiedossier framework — competencyId stays null.',
				['id' => $data['id'] ?? ($data['uuid'] ?? ''), 'code' => $werkprocesCode]
			);
			return;
		}

		$competencyId = $competency['id'] ?? ($competency['uuid'] ?? null);
		if ($competencyId === null) {
			return;
		}

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::WERKPROCES_SCHEMA,
			object: array_merge($data, ['competencyId' => $competencyId])
		);

		$this->logger->info(
			'[CompetencyAttainmentRollupHandler] WerkprocesAssessment {id}: resolved competencyId {cid} from '
			. 'werkprocesCode "{code}".',
			['id' => $data['id'] ?? ($data['uuid'] ?? ''), 'cid' => $competencyId, 'code' => $werkprocesCode]
		);

	}//end resolveWerkprocesCompetencyId()

	/**
	 * Find a Competency whose code matches, scoped to an sbb-kwalificatiedossier framework.
	 *
	 * @param string $code The werkprocesCode to match.
	 * @param string $tenantId Tenant UUID scope filter.
	 *
	 * @return array<string,mixed>|null The matching Competency data, or null when none found.
	 *
	 * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	private function findCompetencyByCode(string $code, string $tenantId): ?array {
		$frameworkFilters = ['sourceAuthority' => self::SBB_SOURCE_AUTHORITY];
		if ($tenantId !== '') {
			$frameworkFilters['tenant_id'] = $tenantId;
		}

		$frameworks = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::FRAMEWORK_SCHEMA,
				'filters' => $frameworkFilters,
			]
		);

		foreach ($frameworks as $framework) {
			$frameworkData = $this->reader->toArray(object: $framework);
			$frameworkId = $frameworkData['id'] ?? ($frameworkData['uuid'] ?? null);
			if ($frameworkId === null) {
				continue;
			}

			$competencyFilters = ['frameworkId' => $frameworkId, 'code' => $code];
			if ($tenantId !== '') {
				$competencyFilters['tenant_id'] = $tenantId;
			}

			$competencies = $this->objectService->findAll(
				[
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::COMPETENCY_SCHEMA,
					'filters' => $competencyFilters,
					'limit' => 1,
				]
			);

			if (empty($competencies) === false) {
				return $this->reader->toArray(object: $competencies[0]);
			}
		}//end foreach

		return null;
	}//end findCompetencyByCode()

	/**
	 * Roll up a confirmed WerkprocesAssessment with a resolved competencyId.
	 *
	 * Uses the assessment's own generalized competencyId directly — no join
	 * needed. A null competencyId (unresolved kwalificatiedossier code) is a
	 * no-op: confirmation is never blocked by this handler.
	 *
	 * @param array<string,mixed> $assessment The confirmed WerkprocesAssessment data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	private function handleWerkprocesConfirmed(array $assessment): void {
		$competencyId = $assessment['competencyId'] ?? null;
		if (empty($competencyId) === true) {
			return;
		}

		$bpvPlacementId = $assessment['bpvPlacementId'] ?? '';
		$placement = $this->reader->load(schema: self::BPV_PLACEMENT_SCHEMA, id: (string)$bpvPlacementId);
		if ($placement === null) {
			return;
		}

		$learnerId = $placement['learnerId'] ?? '';
		if ($learnerId === '') {
			return;
		}

		$tenantId = $placement['tenant_id'] ?? ($assessment['tenant_id'] ?? '');

		$beoordeling = $assessment['assessment'] ?? '';
		$levelId = $this->levelResolver->resolveLevelByLabel(competencyId: $competencyId, assessment: $beoordeling);

		$assessmentId = $assessment['id'] ?? ($assessment['uuid'] ?? '');
		$this->attainment->upsertAttainment(
			learnerId: $learnerId,
			competencyId: $competencyId,
			tenantId: $tenantId,
			evidenceAppend: ['werkprocesAssessmentIds' => $assessmentId],
			percent: null,
			levelId: $levelId
		);

	}//end handleWerkprocesConfirmed()
}//end class
