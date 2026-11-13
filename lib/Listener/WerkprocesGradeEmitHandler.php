<?php

/**
 * Learniq Werkproces Grade Emit Handler
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * IEventListener for WerkprocesAssessment lifecycle → `confirmed` (the OR
 * ObjectTransitionedEvent with register=learniq, schema=werkproces-assessment,
 * to=confirmed). Bridges a confirmed workplace assessment into the `grading`
 * spec's pipeline by emitting (create case) or updating (existing-entry case)
 * exactly one GradeEntry for the assessment's curriculumPlanId/componentId —
 * matching GradeRollupHandler::handleAssessmentResultGraded()'s existing
 * cross-schema write-bridge shape. WerkprocesAssessment computes no final
 * grade itself; the emitted GradeEntry is consumed by grading's existing
 * roll-up (GradeRollupHandler::handleGradeEntryPublished()) once a teacher
 * publishes it.
 *
 * Value mapping: GradeEntry.value is a numeric mark on a GradeScale (no
 * schema change to GradeEntry ships with this change, per design.md — its
 * sourceKind enum and value type are left untouched). WerkprocesAssessment's
 * `beoordeling` is a binary competency outcome, so this handler maps it onto
 * a 0/1 pass-scale: `competent` → 1.0, `nog-niet-competent` → 0.0. sourceKind
 * is stamped `manual` (the closest existing enum value — a human-entered
 * workplace assessment, not an auto-scored assignment/assessment result).
 *
 * ADR-031 legitimate exception: cross-schema event-to-object-write bridge
 * (WerkprocesAssessment → BpvPlacement/CurriculumPlan lookups → GradeEntry
 * write) that cannot be expressed as a schema declaration.
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
 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ObjectRowReader;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges WerkprocesAssessment.confirmed → GradeEntry create/update.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */
class WerkprocesGradeEmitHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ASSESSMENT_SCHEMA = 'werkproces-assessment';
	private const PLACEMENT_SCHEMA = 'bpv-placement';
	private const CURRICULUM_SCHEMA = 'curriculum-plan';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';

	/**
	 * Numeric mapping from `beoordeling` to a 0/1 pass-scale GradeEntry.value.
	 *
	 * @var array<string,float>
	 */
	private const BEOORDELING_VALUE = [
		'competent' => 1.0,
		'nog-niet-competent' => 0.0,
	];

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
	 * @param ListenerSchemaResolver $schemas Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolver $schemas,
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
	 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($this->schemas->eventSchema(event: $event) !== self::ASSESSMENT_SCHEMA) {
			return;
		}

		if ($event->getTo() !== 'confirmed') {
			return;
		}

		$this->emitGradeEntry(event: $event);

	}//end handle()

	/**
	 * Emit or update the GradeEntry bridged from a confirmed WerkprocesAssessment.
	 *
	 * @param ObjectTransitionedEvent $event The confirmed transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	private function emitGradeEntry(ObjectTransitionedEvent $event): void {
		$assessment = $event->getObject()->jsonSerialize();
		$refs = $this->assessmentRefs(assessment: $assessment);
		if ($refs === null) {
			return;
		}

		$learner = $this->placementLearner(placementId: $refs['placement']);
		if ($learner === null) {
			return;
		}

		$curriculumPlan = $this->loadObject(schema: self::CURRICULUM_SCHEMA, id: $refs['curriculum']);

		$existing = $this->findExistingGradeEntry(
			learnerId: $learner['learnerId'],
			curriculumPlanId: $refs['curriculum'],
			componentId: $refs['component'],
			tenantId: $learner['tenantId']
		);

		// System context (grade-entry): coordinators and praktijkopleiders confirm a werkproces
		// assessment, and GradeEntry create is instructors, hr, compliance officers and team leads
		// only.
		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::GRADE_ENTRY_SCHEMA,
			object: $this->gradeEntryData(
				refs: $refs,
				learner: $learner,
				gradeScaleId: ($curriculumPlan['gradeScaleId'] ?? ''),
				existing: $existing
			),
			_rbac: false
		);

		$kind = 'created';
		if ($existing !== null) {
			$kind = 'updated';
		}

		$this->logger->info(
			'[WerkprocesGradeEmitHandler] WerkprocesAssessment {aid} confirmed → GradeEntry {kind} for learner '
			. '{learner}, component {component}.',
			[
				'aid' => self::assessmentId(assessment: $assessment),
				'kind' => $kind,
				'learner' => $learner['learnerId'],
				'component' => $refs['component'],
			]
		);

	}//end emitGradeEntry()

	/**
	 * The references and beoordeling of a confirmed assessment, or null (logged) when one is missing or unmappable.
	 *
	 * @param array<string, mixed> $assessment The assessment data.
	 *
	 * @return array{placement: string, curriculum: string, component: string, beoordeling: string}|null
	 */
	private function assessmentRefs(array $assessment): ?array {
		$refs = [
			'placement' => $assessment['bpvPlacementId'] ?? '',
			'curriculum' => $assessment['curriculumPlanId'] ?? '',
			'component' => $assessment['componentId'] ?? '',
			'beoordeling' => $assessment['assessment'] ?? '',
		];

		if ($refs['placement'] === '' || $refs['curriculum'] === '' || $refs['component'] === '') {
			$this->logger->warning(
				'[WerkprocesGradeEmitHandler] Confirmed WerkprocesAssessment {id} missing bpvPlacementId/'
				. 'curriculumPlanId/componentId — cannot emit GradeEntry.',
				['id' => self::assessmentId(assessment: $assessment)]
			);
			return null;
		}

		if (array_key_exists($refs['beoordeling'], self::BEOORDELING_VALUE) === false) {
			$this->logger->warning(
				'[WerkprocesGradeEmitHandler] Confirmed WerkprocesAssessment {id} has unrecognised beoordeling '
				. '"{b}" — cannot map to a GradeEntry value.',
				['id' => self::assessmentId(assessment: $assessment), 'b' => $refs['beoordeling']]
			);
			return null;
		}

		return $refs;
	}//end assessmentRefs()

	/**
	 * The learner and tenant of a BPV placement, or null (logged) when the placement or its learner is missing.
	 *
	 * @param string $placementId The BpvPlacement id.
	 *
	 * @return array{learnerId: string, tenantId: string}|null
	 */
	private function placementLearner(string $placementId): ?array {
		$placement = $this->loadObject(schema: self::PLACEMENT_SCHEMA, id: $placementId);
		if ($placement === null) {
			$this->logger->warning(
				'[WerkprocesGradeEmitHandler] BpvPlacement {id} referenced by WerkprocesAssessment not found.',
				['id' => $placementId]
			);
			return null;
		}

		$learnerId = $placement['learnerId'] ?? '';
		if ($learnerId === '') {
			$this->logger->warning(
				'[WerkprocesGradeEmitHandler] BpvPlacement {id} has no learnerId — cannot emit GradeEntry.',
				['id' => $placementId]
			);
			return null;
		}

		return ['learnerId' => $learnerId, 'tenantId' => $placement['tenant_id'] ?? ''];
	}//end placementLearner()

	/**
	 * The GradeEntry to write: the existing entry, if any, with the assessment's fields over it.
	 *
	 * @param array{placement: string, curriculum: string, component: string, beoordeling: string} $refs         The assessment references.
	 * @param array{learnerId: string, tenantId: string}                                          $learner      The placement's learner and tenant.
	 * @param mixed                                                                               $gradeScaleId The curriculum plan's grade scale.
	 * @param array<string, mixed>|null                                                           $existing     The existing GradeEntry, if any.
	 *
	 * @return array<string, mixed>
	 */
	private function gradeEntryData(array $refs, array $learner, mixed $gradeScaleId, ?array $existing): array {
		return array_merge(
			$existing ?? [],
			[
				'learnerId' => $learner['learnerId'],
				'curriculumPlanId' => $refs['curriculum'],
				'componentId' => $refs['component'],
				'sourceKind' => 'manual',
				'value' => self::BEOORDELING_VALUE[$refs['beoordeling']],
				'gradeScaleId' => $gradeScaleId,
				'grader' => 'praktijkopleider',
				'gradedAt' => (new DateTimeImmutable())->format(\DATE_ATOM),
				'tenant_id' => $learner['tenantId'],
				'lifecycle' => $existing['lifecycle'] ?? 'concept',
			]
		);
	}//end gradeEntryData()

	/**
	 * The id a log line names an assessment by: its id, else its uuid.
	 *
	 * @param array<string, mixed> $assessment The assessment data.
	 *
	 * @return mixed
	 */
	private static function assessmentId(array $assessment): mixed {
		return $assessment['id'] ?? ($assessment['uuid'] ?? '');
	}//end assessmentId()

	/**
	 * Load a single OpenRegister object by id.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Object UUID.
	 *
	 * @return array<string,mixed>|null The object data, or null when not found.
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	private function loadObject(string $schema, string $id): ?array {
		return $this->rows->load(schema: $schema, id: $id);
	}//end loadObject()

	/**
	 * Find an existing GradeEntry for the same learner/plan/component pair, if any.
	 *
	 * @param string $learnerId NC user id.
	 * @param string $curriculumPlanId Plan UUID.
	 * @param string $componentId Component identifier.
	 * @param string $tenantId Tenant UUID scope filter.
	 *
	 * @return array<string,mixed>|null The existing GradeEntry data, or null when none exists.
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	private function findExistingGradeEntry(
		string $learnerId,
		string $curriculumPlanId,
		string $componentId,
		string $tenantId,
	): ?array {
		$filters = [
			'learnerId' => $learnerId,
			'curriculumPlanId' => $curriculumPlanId,
			'componentId' => $componentId,
		];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::GRADE_ENTRY_SCHEMA,
					]
				),
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		if (is_array($results[0]) === true) {
			return $results[0];
		}

		return $results[0]->jsonSerialize();
	}//end findExistingGradeEntry()
}//end class
