<?php

/**
 * Scholiq Report Card Composer
 *
 * IEventListener for two ObjectTransitionedEvents (register=scholiq):
 *  - schema=report-period, action=compose (open -> composed): composes one
 *    `draft` ReportCard per learner in `cohortIds[] -> Cohort.learnerIds`.
 *  - schema=report-card, action=recompose (draft -> draft self-loop):
 *    re-runs the same composition for a single, already-existing `draft`
 *    ReportCard, overwriting its `subjectGrades[]`/`attendanceSummary`.
 *
 * Mirrors DataExchangeRunHandler::composeLeerplichtDossier()/
 * composeSwvDossier()'s "assemble from multiple linked objects" shape
 * (ADR-031 cross-object write bridge) — NOT the DataExchangeJob queue those
 * methods live in, per report-card's own "Composition is a declared-
 * transition-triggered PHP composer, not a DataExchangeJob and not a
 * TimedJob" requirement.
 *
 * For every learner in scope, for every `curriculumPlanId` in
 * `ReportPeriod.curriculumPlanIds[]` whose `CurriculumPlan.components[]`
 * declares a component matching the period's `periodCode`, reads the
 * learner's `FinalGrade.breakdown.periods[periodCode]` (already computed by
 * GradeRollupHandler — this class NEVER recomputes it) plus the contributing
 * published `GradeEntry` ids for that (learner, curriculumPlanId, period),
 * and — when `attendanceIncluded` — aggregates `AttendanceRecord`s within
 * `[startDate, endDate]` (via a two-step Session-then-AttendanceRecord fetch,
 * mirroring `TimetableController::fetchSessions()`'s window-overlap style).
 * A subject with no matching period component contributes no
 * `subjectGrades[]` row, not an error, per report-card's own scenario.
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-composition-is-a-declared-transition-triggered-php-composer-not-a-dataexchangejob-and-not-a-timedjob
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-composing-a-period-creates-one-reportcard-per-cohort-learner
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-subject-with-no-matching-period-component-contributes-no-row-not-an-error
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\AttendanceWindowAggregator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Composes ReportCards for a ReportPeriod's `compose` transition, and
 * re-composes a single ReportCard for its own `recompose` self-loop.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-composition-is-a-declared-transition-triggered-php-composer-not-a-dataexchangejob-and-not-a-timedjob
 */
class ReportCardComposer implements IEventListener {

	private const SCHOLIQ_REGISTER = 'scholiq';
	private const REPORT_PERIOD_SCHEMA = 'report-period';
	private const REPORT_CARD_SCHEMA = 'report-card';
	private const COHORT_SCHEMA = 'cohort';
	private const CURRICULUM_PLAN_SCHEMA = 'curriculum-plan';
	private const FINAL_GRADE_SCHEMA = 'final-grade';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param ITimeFactory $timeFactory NC time source (injectable "now" for tests).
	 * @param LoggerInterface $logger PSR logger.
	 * @param AttendanceWindowAggregator $attendance Resolves window Sessions and aggregates attendance.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly AttendanceWindowAggregator $attendance,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-composing-a-period-creates-one-reportcard-per-cohort-learner
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
			return;
		}

		if ($event->getSchema() === self::REPORT_PERIOD_SCHEMA && $event->getAction() === 'compose') {
			$this->composeForPeriod(period: $event->getObject()->jsonSerialize());
			return;
		}

		if ($event->getSchema() === self::REPORT_CARD_SCHEMA && $event->getAction() === 'recompose') {
			$this->recomposeCard(card: $event->getObject()->jsonSerialize());
		}

	}//end handle()

	/**
	 * Compose one `draft` ReportCard per learner in `cohortIds[] ->
	 * Cohort.learnerIds` for a ReportPeriod that just transitioned to
	 * `composed`.
	 *
	 * @param array<string,mixed> $period The ReportPeriod data array.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-composing-a-period-creates-one-reportcard-per-cohort-learner
	 */
	private function composeForPeriod(array $period): void {
		$periodId = (string)($period['id'] ?? ($period['uuid'] ?? ''));
		$periodCode = (string)($period['periodCode'] ?? '');

		if ($periodId === '' || $periodCode === '') {
			$this->logger->warning('[ReportCardComposer] ReportPeriod missing id/periodCode; aborting composition.');
			return;
		}

		$curriculumPlanIds = $this->stringList(value: $period['curriculumPlanIds'] ?? []);
		$cohortIds = $this->stringList(value: $period['cohortIds'] ?? []);
		$startDate = (string)($period['startDate'] ?? '');
		$endDate = (string)($period['endDate'] ?? '');
		$attendanceIncluded = ($period['attendanceIncluded'] ?? true) === true;
		$tenantId = (string)($period['tenant_id'] ?? '');

		$qualifyingPlanIds = $this->qualifyingCurriculumPlanIds(curriculumPlanIds: $curriculumPlanIds, periodCode: $periodCode);

		[$learnerCohortMap, $learnerIds] = $this->resolveLearnersByCohort(cohortIds: $cohortIds);

		$sessionIds = [];
		if ($attendanceIncluded === true) {
			$sessionIds = $this->attendance->fetchWindowSessionIds(cohortIds: $cohortIds, startDate: $startDate, endDate: $endDate);
		}

		$createdCount = 0;

		foreach ($learnerIds as $learnerId) {
			$subjectGrades = $this->buildSubjectGrades(
				learnerId: $learnerId,
				curriculumPlanIds: $qualifyingPlanIds,
				periodCode: $periodCode
			);
			$attendanceSummary = null;
			if ($attendanceIncluded === true) {
				$attendanceSummary = $this->attendance->buildAttendanceSummary(learnerId: $learnerId, sessionIds: $sessionIds);
			}

			$reportCard = [
				'learnerId' => $learnerId,
				'learnerRef' => $this->resolveLearnerRef(learnerId: $learnerId),
				'reportPeriodId' => $periodId,
				'cohortId' => $learnerCohortMap[$learnerId] ?? null,
				'subjectGrades' => $subjectGrades,
				'attendanceSummary' => $attendanceSummary,
				'mentorComment' => null,
				'competencyAttainment' => null,
				'composedAt' => $this->now(),
				'docudeskRenderStatus' => null,
				'docudeskRequestedAt' => null,
				'docudeskDocumentRef' => null,
				'docudeskRenderError' => null,
				'tenant_id' => $tenantId,
				'lifecycle' => 'draft',
			];

			$this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: self::REPORT_CARD_SCHEMA, object: $reportCard);
			$createdCount++;
		}//end foreach

		$this->logger->info(
			'[ReportCardComposer] ReportPeriod {period}: {count} ReportCard(s) composed.',
			['period' => $periodId, 'count' => $createdCount]
		);

	}//end composeForPeriod()

	/**
	 * Re-run composition for a single, already-existing `draft` ReportCard
	 * (the `recompose` self-loop), overwriting its `subjectGrades[]` and
	 * `attendanceSummary`.
	 *
	 * @param array<string,mixed> $card The ReportCard data array (post-transition, still `draft`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-composition-is-a-declared-transition-triggered-php-composer-not-a-dataexchangejob-and-not-a-timedjob
	 */
	private function recomposeCard(array $card): void {
		$cardId = (string)($card['id'] ?? ($card['uuid'] ?? ''));
		$learnerId = (string)($card['learnerId'] ?? '');
		$reportPeriodId = (string)($card['reportPeriodId'] ?? '');

		if ($cardId === '' || $learnerId === '' || $reportPeriodId === '') {
			$this->logger->warning(
				'[ReportCardComposer] ReportCard {id} missing id/learnerId/reportPeriodId; aborting recompose.',
				['id' => $cardId]
			);
			return;
		}

		$period = $this->objectService->find(id: $reportPeriodId, register: self::SCHOLIQ_REGISTER, schema: self::REPORT_PERIOD_SCHEMA);
		if ($period === null) {
			$this->logger->warning(
				'[ReportCardComposer] ReportCard {id} recompose: governing ReportPeriod {period} not found.',
				['id' => $cardId, 'period' => $reportPeriodId]
			);
			return;
		}

		$periodData = $this->normalise(row: $period);

		$periodCode = (string)($periodData['periodCode'] ?? '');
		$curriculumPlanIds = $this->stringList(value: $periodData['curriculumPlanIds'] ?? []);
		$attendanceIncluded = ($periodData['attendanceIncluded'] ?? true) === true;
		$startDate = (string)($periodData['startDate'] ?? '');
		$endDate = (string)($periodData['endDate'] ?? '');

		$qualifyingPlanIds = $this->qualifyingCurriculumPlanIds(curriculumPlanIds: $curriculumPlanIds, periodCode: $periodCode);

		$subjectGrades = $this->buildSubjectGrades(learnerId: $learnerId, curriculumPlanIds: $qualifyingPlanIds, periodCode: $periodCode);

		$attendanceSummary = null;
		if ($attendanceIncluded === true) {
			$cohortIds = $this->stringList(value: $periodData['cohortIds'] ?? []);
			$sessionIds = $this->attendance->fetchWindowSessionIds(cohortIds: $cohortIds, startDate: $startDate, endDate: $endDate);
			$attendanceSummary = $this->attendance->buildAttendanceSummary(learnerId: $learnerId, sessionIds: $sessionIds);
		}

		$updated = $card;
		$updated['subjectGrades'] = $subjectGrades;
		$updated['attendanceSummary'] = $attendanceSummary;
		$updated['composedAt'] = $this->now();

		$this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: self::REPORT_CARD_SCHEMA, object: $updated);

		$this->logger->info('[ReportCardComposer] ReportCard {id} recomposed.', ['id' => $cardId]);

	}//end recomposeCard()

	/**
	 * Filter `curriculumPlanIds` down to those whose `CurriculumPlan.components[]`
	 * declares at least one component matching `periodCode`.
	 *
	 * @param array<int,string> $curriculumPlanIds Candidate CurriculumPlan UUIDs.
	 * @param string $periodCode The ReportPeriod's periodCode.
	 *
	 * @return array<int,string> The subset that qualifies.
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-subject-with-no-matching-period-component-contributes-no-row-not-an-error
	 */
	private function qualifyingCurriculumPlanIds(array $curriculumPlanIds, string $periodCode): array {
		$qualifying = [];

		foreach ($curriculumPlanIds as $curriculumPlanId) {
			$plan = $this->objectService->find(id: $curriculumPlanId, register: self::SCHOLIQ_REGISTER, schema: self::CURRICULUM_PLAN_SCHEMA);
			if ($plan === null) {
				continue;
			}

			$planData = $this->normalise(row: $plan);
			$components = $planData['components'] ?? [];
			if (is_array($components) === false) {
				continue;
			}

			foreach ($components as $component) {
				if (is_array($component) === false) {
					continue;
				}

				if ((string)($component['period'] ?? '') === $periodCode) {
					$qualifying[] = $curriculumPlanId;
					break;
				}
			}
		}//end foreach

		return $qualifying;
	}//end qualifyingCurriculumPlanIds()

	/**
	 * Resolve every learner in `cohortIds[] -> Cohort.learnerIds`, and a
	 * learnerId => cohortId map (first cohort a learner is found in wins,
	 * denormalised onto the composed ReportCard).
	 *
	 * @param array<int,string> $cohortIds ReportPeriod.cohortIds.
	 *
	 * @return array{0:array<string,string>,1:array<int,string>} [learnerId => cohortId map, unique learnerIds list].
	 */
	private function resolveLearnersByCohort(array $cohortIds): array {
		$learnerCohortMap = [];

		foreach ($cohortIds as $cohortId) {
			$cohort = $this->objectService->find(id: $cohortId, register: self::SCHOLIQ_REGISTER, schema: self::COHORT_SCHEMA);
			if ($cohort === null) {
				continue;
			}

			$cohortData = $this->normalise(row: $cohort);
			$learnerIds = $this->stringList(value: $cohortData['learnerIds'] ?? []);

			foreach ($learnerIds as $learnerId) {
				if (isset($learnerCohortMap[$learnerId]) === false) {
					$learnerCohortMap[$learnerId] = $cohortId;
				}
			}
		}//end foreach

		return [$learnerCohortMap, array_keys($learnerCohortMap)];
	}//end resolveLearnersByCohort()

	/**
	 * Build the `subjectGrades[]` array for a learner: one row per qualifying
	 * `curriculumPlanId`, populated from that learner's
	 * `FinalGrade.breakdown.periods[periodCode]` plus the contributing
	 * published `GradeEntry` ids for that (learner, curriculumPlanId, period).
	 *
	 * @param string $learnerId NC user ID.
	 * @param array<int,string> $curriculumPlanIds Qualifying CurriculumPlan UUIDs.
	 * @param string $periodCode The ReportPeriod's periodCode.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function buildSubjectGrades(string $learnerId, array $curriculumPlanIds, string $periodCode): array {
		$rows = [];

		foreach ($curriculumPlanIds as $curriculumPlanId) {
			$finalGrades = $this->objectService->findAll(
				[
					'register' => self::SCHOLIQ_REGISTER,
					'schema' => self::FINAL_GRADE_SCHEMA,
					'filters' => [
						'learnerId' => $learnerId,
						'curriculumPlanId' => $curriculumPlanId,
					],
					'limit' => 1,
				]
			);

			$finalGrade = [];
			if (empty($finalGrades) === false) {
				$finalGrade = $this->normalise(row: $finalGrades[0]);
			}

			$breakdown = $finalGrade['breakdown'] ?? [];

			$periods = [];
			if (is_array($breakdown) === true) {
				$periods = $breakdown['periods'] ?? [];
			}

			$periodAverage = null;
			if (is_array($periods) === true) {
				$periodAverage = $periods[$periodCode] ?? null;
			}

			$sourceGradeEntries = $this->objectService->findAll(
				[
					'register' => self::SCHOLIQ_REGISTER,
					'schema' => self::GRADE_ENTRY_SCHEMA,
					'filters' => [
						'learnerId' => $learnerId,
						'curriculumPlanId' => $curriculumPlanId,
						'period' => $periodCode,
						'lifecycle' => 'published',
					],
					'limit' => 500,
				]
			);

			$sourceGradeEntryIds = array_values(
				array_filter(
					array_map(
						function ($entry): string {
							$entry = $this->normalise(row: $entry);
							return (string)($entry['id'] ?? ($entry['uuid'] ?? ''));
						},
						$sourceGradeEntries
					),
					static fn (string $id): bool => $id !== ''
				)
			);

			$rows[] = [
				'curriculumPlanId' => $curriculumPlanId,
				'courseId' => $finalGrade['courseId'] ?? null,
				'periodAverage' => $this->toNullableFloat(value: $periodAverage),
				'passed' => $finalGrade['passed'] ?? null,
				'teacherComment' => null,
				'sourceGradeEntryIds' => $sourceGradeEntryIds,
			];
		}//end foreach

		return $rows;
	}//end buildSubjectGrades()

	/**
	 * Resolve a learner's `LearnerProfile` object UUID (ADR-046 `learnerRef`),
	 * mirroring the `learnerId` filter shape every other cross-schema
	 * LearnerProfile lookup in this app already uses (e.g.
	 * `GradeRollupHandler::fanOutParentNotifications()`).
	 *
	 * @param string $learnerId NC user ID.
	 *
	 * @return string|null The LearnerProfile object UUID, or null when unresolvable.
	 */
	private function resolveLearnerRef(string $learnerId): ?string {
		$profiles = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::LEARNER_PROFILE_SCHEMA,
				'filters' => ['learnerId' => $learnerId],
				'limit' => 1,
			]
		);

		if (empty($profiles) === true) {
			return null;
		}

		$profile = $this->normalise(row: $profiles[0]);

		$ref = $profile['id'] ?? ($profile['uuid'] ?? null);

		if ($ref === null) {
			return null;
		}

		return (string)$ref;
	}//end resolveLearnerRef()

	/**
	 * Current moment as an ISO-8601 string, via the injected time source.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->timeFactory->getDateTime()->format(\DATE_ATOM);
	}//end now()

	/**
	 * Coerce a mixed value into a float, or null when not numeric.
	 *
	 * @param mixed $value Raw FinalGrade.breakdown.periods[periodCode] value.
	 *
	 * @return float|null
	 */
	private function toNullableFloat(mixed $value): ?float {
		if (is_numeric($value) === false) {
			return null;
		}

		return (float)$value;
	}//end toNullableFloat()

	/**
	 * Coerce a mixed array value into a list of non-empty strings.
	 *
	 * @param mixed $value Raw property value.
	 *
	 * @return array<int,string>
	 */
	private function stringList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(static fn ($item): string => (string)$item, $value),
				static fn (string $item): bool => $item !== ''
			)
		);

	}//end stringList()

	/**
	 * Normalise an ObjectService row/entity to a plain array.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll()/find().
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return $row->jsonSerialize();
	}//end normalise()
}//end class
