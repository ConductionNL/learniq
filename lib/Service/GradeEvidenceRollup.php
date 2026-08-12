<?php

/**
 * Scholiq Grade Evidence Rollup
 *
 * The published-GradeEntry half of competency roll-up, extracted from
 * `CompetencyAttainmentRollupHandler` so each class carries one cohesive
 * responsibility: this one answers "which competencies does this published
 * GradeEntry evidence, and how well?" by walking the two supported source
 * chains — `submissionId -> Submission.assignmentId -> Assignment.competencyIds`
 * and `assessmentResultId -> AssessmentResult.assessmentId ->
 * Assessment.competencyIds` — computing the evidence percentage, and handing
 * each competency to `CompetencyAttainmentWriter`. The handler keeps the event
 * routing and the WerkprocesAssessment paths.
 *
 * Every hop that comes up empty is a silent no-op: an unaligned or unresolvable
 * GradeEntry must never block the publish that produced it.
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
 * @spec openspec/changes/competency-framework/specs/assignments/spec.md#requirement-assignment-declares-which-competencies-it-assesses
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

/**
 * Rolls a published GradeEntry's evidence into the competencies its source declares.
 */
class GradeEvidenceRollup {

	private const SUBMISSION_SCHEMA = 'submission';
	private const ASSIGNMENT_SCHEMA = 'assignment';
	private const ASSESSMENT_RESULT_SCHEMA = 'assessment-result';
	private const ASSESSMENT_SCHEMA = 'assessment';

	/**
	 * Constructor.
	 *
	 * @param ObjectRowReader $reader Reads Submission/Assignment/Assessment rows by id.
	 * @param CompetencyAttainmentWriter $attainment Upserts CompetencyAttainment rows and appends evidence.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectRowReader $reader,
		private readonly CompetencyAttainmentWriter $attainment,
	) {
	}//end __construct()

	/**
	 * Roll up a published GradeEntry's evidence into CompetencyAttainment, if aligned.
	 *
	 * @param array<string,mixed> $entry The published GradeEntry data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	public function rollupPublishedGradeEntry(array $entry): void {
		$sourceKind = $entry['sourceKind'] ?? '';

		if ($sourceKind === 'assignment-submission') {
			$this->rollupFromAssignmentSubmission(entry: $entry);
			return;
		}

		if ($sourceKind === 'assessment-result') {
			$this->rollupFromAssessmentResult(entry: $entry);
		}

	}//end rollupPublishedGradeEntry()

	/**
	 * Roll up an assignment-submission-sourced GradeEntry.
	 *
	 * Resolves submissionId -> Submission.assignmentId -> Assignment.competencyIds.
	 *
	 * @param array<string,mixed> $entry The published GradeEntry data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/assignments/spec.md#requirement-assignment-declares-which-competencies-it-assesses
	 */
	private function rollupFromAssignmentSubmission(array $entry): void {
		$submissionId = $entry['submissionId'] ?? '';

		$submission = $this->reader->load(schema: self::SUBMISSION_SCHEMA, id: (string)$submissionId);
		if ($submission === null) {
			return;
		}

		$assignment = $this->reader->load(
			schema: self::ASSIGNMENT_SCHEMA,
			id: (string)($submission['assignmentId'] ?? '')
		);
		if ($assignment === null) {
			return;
		}

		$this->appendToEach(
			competencyIds: ($assignment['competencyIds'] ?? []),
			entry: $entry,
			evidenceAppend: [
				'gradeEntryIds' => $entry['id'] ?? ($entry['uuid'] ?? ''),
				'submissionIds' => $submissionId,
			],
			percent: $this->percentageFor(value: $entry['value'] ?? null, maxPoints: $assignment['maxPoints'] ?? null)
		);

	}//end rollupFromAssignmentSubmission()

	/**
	 * Roll up an assessment-result-sourced GradeEntry.
	 *
	 * Resolves assessmentResultId -> AssessmentResult.assessmentId -> Assessment.competencyIds.
	 *
	 * @param array<string,mixed> $entry The published GradeEntry data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/assessment/spec.md#requirement-assessment-declares-which-competencies-it-assesses-and-item-carries-competency-tags-for-authoring
	 */
	private function rollupFromAssessmentResult(array $entry): void {
		$assessmentResultId = $entry['assessmentResultId'] ?? '';

		$assessmentResult = $this->reader->load(
			schema: self::ASSESSMENT_RESULT_SCHEMA,
			id: (string)$assessmentResultId
		);
		if ($assessmentResult === null) {
			return;
		}

		$assessment = $this->reader->load(
			schema: self::ASSESSMENT_SCHEMA,
			id: (string)($assessmentResult['assessmentId'] ?? '')
		);
		if ($assessment === null) {
			return;
		}

		$this->appendToEach(
			competencyIds: ($assessment['competencyIds'] ?? []),
			entry: $entry,
			evidenceAppend: [
				'gradeEntryIds' => $entry['id'] ?? ($entry['uuid'] ?? ''),
				'assessmentResultIds' => $assessmentResultId,
			],
			percent: $this->percentageFor(
				value: $entry['value'] ?? null,
				maxPoints: $this->assessmentMaxPoints(assessment: $assessment)
			)
		);

	}//end rollupFromAssessmentResult()

	/**
	 * Append the same evidence to every competency the source declares.
	 *
	 * An empty `$competencyIds` is a no-op: a source that declares no
	 * competencies simply evidences none.
	 *
	 * @param mixed $competencyIds The source's declared competencyIds.
	 * @param array<string,mixed> $entry The published GradeEntry data (learner/tenant scope).
	 * @param array<string,string|null> $evidenceAppend Map of evidence-array field name to the id to append.
	 * @param float|null $percent Evidence percentage, or null when not computable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	private function appendToEach(mixed $competencyIds, array $entry, array $evidenceAppend, ?float $percent): void {
		if (is_array($competencyIds) === false) {
			return;
		}

		foreach ($competencyIds as $competencyId) {
			$this->attainment->upsertAttainment(
				learnerId: $entry['learnerId'] ?? '',
				competencyId: $competencyId,
				tenantId: $entry['tenant_id'] ?? '',
				evidenceAppend: $evidenceAppend,
				percent: $percent
			);
		}

	}//end appendToEach()

	/**
	 * Compute an evidence percentage from a raw value and its maximum.
	 *
	 * @param mixed $value Raw GradeEntry value.
	 * @param mixed $maxPoints Maximum achievable points for the source.
	 *
	 * @return float|null The percentage (0-100), or null when not computable.
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	private function percentageFor(mixed $value, mixed $maxPoints): ?float {
		if ($value === null || $maxPoints === null) {
			return null;
		}

		$max = (float)$maxPoints;
		if ($max <= 0.0) {
			return null;
		}

		return ((float)$value / $max) * 100.0;
	}//end percentageFor()

	/**
	 * Sum an Assessment's itemRefs[].points to derive its total achievable points.
	 *
	 * @param array<string,mixed> $assessment The Assessment data.
	 *
	 * @return float|null The summed max points, or null when itemRefs is empty/unset.
	 *
	 * @spec openspec/changes/competency-framework/specs/assessment/spec.md#requirement-assessment-declares-which-competencies-it-assesses-and-item-carries-competency-tags-for-authoring
	 */
	private function assessmentMaxPoints(array $assessment): ?float {
		$itemRefs = $assessment['itemRefs'] ?? [];
		if (is_array($itemRefs) === false || empty($itemRefs) === true) {
			return null;
		}

		$sum = 0.0;
		foreach ($itemRefs as $ref) {
			$sum += (float)($ref['points'] ?? 0);
		}

		if ($sum <= 0.0) {
			return null;
		}

		return $sum;
	}//end assessmentMaxPoints()
}//end class
