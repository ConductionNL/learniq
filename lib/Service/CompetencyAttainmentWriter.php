<?php

/**
 * Scholiq Competency Attainment Writer
 *
 * The persistence half of competency roll-up, extracted from
 * `CompetencyAttainmentRollupHandler` so each class carries one cohesive
 * responsibility: this one owns the find-or-create upsert of a
 * `CompetencyAttainment` row for a (learnerId, competencyId) pair and the
 * idempotent append of evidence ids onto it, while the handler keeps the event
 * routing and the evidence discovery that decides *what* to append.
 *
 * Idempotency is the point: the same GradeEntry, Submission, AssessmentResult
 * or WerkprocesAssessment id may arrive more than once (a redelivered event, a
 * republish), and it must never be appended twice. A blank incoming identity
 * value never overwrites what the existing row already knows.
 *
 * Consumed by:
 *   - CompetencyAttainmentRollupHandler (constructor injection)
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Find-or-creates CompetencyAttainment rows and idempotently appends evidence.
 */
class CompetencyAttainmentWriter {

	private const SCHOLIQ_REGISTER = 'scholiq';
	private const COMPETENCY_SCHEMA = 'competency';
	private const ATTAINMENT_SCHEMA = 'competency-attainment';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param ObjectRowReader $reader Reads Competency/CompetencyAttainment rows by id.
	 * @param CompetencyLevelResolver $levelResolver Resolves a proficiencyLevelId from an evidence percentage.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ObjectRowReader $reader,
		private readonly CompetencyLevelResolver $levelResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Find-or-create a CompetencyAttainment row and idempotently append evidence.
	 *
	 * @param string $learnerId NC user id of the learner.
	 * @param string $competencyId UUID of the aligned Competency.
	 * @param string $tenantId Tenant UUID scope.
	 * @param array<string,string|null> $evidenceAppend Map of evidence-array field name to the id to append.
	 * @param float|null $percent Evidence percentage for threshold-based level resolution,
	 *                            or null when not applicable (e.g. the WerkprocesAssessment path).
	 * @param string|null $levelId An already-resolved levelId (WerkprocesAssessment path);
	 *                             when null, percent-based resolution is attempted instead.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	public function upsertAttainment(
		string $learnerId,
		string $competencyId,
		string $tenantId,
		array $evidenceAppend,
		?float $percent = null,
		?string $levelId = null,
	): void {
		if ($learnerId === '' || $competencyId === '') {
			return;
		}

		$competency = $this->reader->load(schema: self::COMPETENCY_SCHEMA, id: $competencyId);
		if ($competency === null) {
			return;
		}

		$frameworkId = $competency['frameworkId'] ?? '';

		$existing = $this->findExistingAttainment(learnerId: $learnerId, competencyId: $competencyId, tenantId: $tenantId);

		$data = $existing ?? $this->blankAttainment(
			learnerId: $learnerId,
			competencyId: $competencyId,
			frameworkId: (string)$frameworkId,
			tenantId: $tenantId
		);

		$data = $this->appendEvidence(data: $data, evidenceAppend: $evidenceAppend);

		$resolvedLevelId = $this->effectiveLevelId(
			levelId: $levelId,
			frameworkId: (string)$frameworkId,
			percent: $percent
		);
		if ($resolvedLevelId !== null) {
			$data['proficiencyLevelId'] = $resolvedLevelId;
		}

		$data['learnerId'] = $learnerId;
		$data['competencyId'] = $competencyId;

		// A blank incoming value never overwrites what the existing row already knows.
		$data['frameworkId'] = $this->preferNonEmpty(candidate: (string)$frameworkId, current: ($data['frameworkId'] ?? ''));
		$data['tenant_id'] = $this->preferNonEmpty(candidate: $tenantId, current: ($data['tenant_id'] ?? ''));

		$data['lastRecomputedAt'] = (new DateTimeImmutable())->format(\DATE_ATOM);

		$this->objectService->saveObject(
			register: self::SCHOLIQ_REGISTER,
			schema: self::ATTAINMENT_SCHEMA,
			object: $data
		);

		$this->logUpsert(existing: $existing, learnerId: $learnerId, competencyId: $competencyId);

	}//end upsertAttainment()

	/**
	 * The empty CompetencyAttainment row a first piece of evidence starts from.
	 *
	 * @param string $learnerId NC user id of the learner.
	 * @param string $competencyId UUID of the aligned Competency.
	 * @param string $frameworkId UUID of the Competency's framework.
	 * @param string $tenantId Tenant UUID scope.
	 *
	 * @return array<string,mixed> A blank attainment row with every evidence array present.
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	private function blankAttainment(string $learnerId, string $competencyId, string $frameworkId, string $tenantId): array {
		return [
			'learnerId' => $learnerId,
			'competencyId' => $competencyId,
			'frameworkId' => $frameworkId,
			'tenant_id' => $tenantId,
			'gradeEntryIds' => [],
			'assessmentResultIds' => [],
			'werkprocesAssessmentIds' => [],
			'submissionIds' => [],
			'proficiencyLevelId' => null,
		];

	}//end blankAttainment()

	/**
	 * Pick the level id to stamp: an already-resolved one wins, otherwise resolve by percentage.
	 *
	 * @param string|null $levelId An already-resolved levelId (WerkprocesAssessment path), or null.
	 * @param string $frameworkId UUID of the Competency's framework.
	 * @param float|null $percent Evidence percentage, or null when not applicable.
	 *
	 * @return string|null The level id to stamp, or null when none resolves.
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	private function effectiveLevelId(?string $levelId, string $frameworkId, ?float $percent): ?string {
		if ($levelId !== null) {
			return $levelId;
		}

		if ($percent === null) {
			return null;
		}

		return $this->levelResolver->resolveLevelByPercent(frameworkId: $frameworkId, percent: $percent);
	}//end effectiveLevelId()

	/**
	 * Log whether the upsert created or updated the learner's attainment row.
	 *
	 * @param array<string,mixed>|null $existing The row as it was found, or null when none existed.
	 * @param string $learnerId NC user id of the learner.
	 * @param string $competencyId UUID of the aligned Competency.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	private function logUpsert(?array $existing, string $learnerId, string $competencyId): void {
		$kind = 'created';
		if ($existing !== null) {
			$kind = 'updated';
		}

		$this->logger->info(
			'[CompetencyAttainmentWriter] CompetencyAttainment {kind} for learner {learner}, competency {cid}.',
			['kind' => $kind, 'learner' => $learnerId, 'cid' => $competencyId]
		);

	}//end logUpsert()

	/**
	 * Append evidence ids to their arrays on the attainment row, skipping blanks
	 * and ids the row already carries.
	 *
	 * A field whose stored value is not an array is reset to one rather than
	 * being appended to, so a malformed row repairs itself instead of throwing.
	 *
	 * @param array<string,mixed> $data The attainment row being built.
	 * @param array<string,string|null> $evidenceAppend Map of evidence-array field name to the id to append.
	 *
	 * @return array<string,mixed> The row with its evidence arrays updated.
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	private function appendEvidence(array $data, array $evidenceAppend): array {
		foreach ($evidenceAppend as $field => $id) {
			if ($id === '' || $id === null) {
				continue;
			}

			$arr = ($data[$field] ?? []);
			if (is_array($arr) === false) {
				$arr = [];
			}

			if (in_array($id, $arr, true) === false) {
				$arr[] = $id;
			}

			$data[$field] = $arr;
		}

		return $data;
	}//end appendEvidence()

	/**
	 * Keep an incoming value only when it actually says something.
	 *
	 * Used for the identity fields an upsert re-stamps: a blank candidate must
	 * never blank out what the existing row already knows.
	 *
	 * @param string $candidate The incoming value.
	 * @param mixed $current The value already on the row.
	 *
	 * @return string The candidate when non-empty, otherwise the current value.
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	private function preferNonEmpty(string $candidate, mixed $current): string {
		if ($candidate !== '') {
			return $candidate;
		}

		return (string)$current;
	}//end preferNonEmpty()

	/**
	 * Find an existing CompetencyAttainment row for a (learnerId, competencyId) pair.
	 *
	 * @param string $learnerId NC user id.
	 * @param string $competencyId Competency UUID.
	 * @param string $tenantId Tenant UUID scope filter.
	 *
	 * @return array<string,mixed>|null The existing row data, or null when none exists.
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	private function findExistingAttainment(string $learnerId, string $competencyId, string $tenantId): ?array {
		$filters = [
			'learnerId' => $learnerId,
			'competencyId' => $competencyId,
		];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::ATTAINMENT_SCHEMA,
				'filters' => $filters,
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		return $this->reader->toArray(object: $results[0]);
	}//end findExistingAttainment()
}//end class
