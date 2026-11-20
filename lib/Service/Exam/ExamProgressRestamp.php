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

namespace OCA\Learniq\Service\Exam;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Keuzedeel\KeuzedeelRules;
use OCA\Learniq\Service\Keuzedeel\KeuzedeelStore;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes a student's exam progress, next sitting and keuzedeel line onto her
 * learner profile (the page's "Examens en keuzedelen" block).
 *
 * Reads her programme enrolment, its curriculum plan, her grade entries and
 * the planned sittings of her cohorts, derives the lines with ExamProgress
 * and saves them only when they differ. Never fatal to the write that
 * triggered it.
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T4
 */
class ExamProgressRestamp {

	/**
	 * The learniq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * The fields this restamp writes on the profile.
	 *
	 * @var list<string>
	 */
	public const FIELDS = [
		'examComponentsPassed',
		'examComponentsTotal',
		'examProgressLabel',
		'nextExamSittingId',
		'nextExamLabel',
		'keuzedeelLabel'
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the student's records and writes the profile.
	 * @param LoggerInterface $logger        Logs a failed restamp.
	 * @param ExamProgress    $progress      Derives the lines.
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T4
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ExamProgress $progress = new ExamProgress(),
	) {
	}//end __construct()

	/**
	 * Restamp the student with this Nextcloud user id.
	 *
	 * @param string                 $learnerUserId The student's user id.
	 * @param DateTimeImmutable|null $now           The moment; now when null.
	 *
	 * @return bool True when the profile was written.
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T4
	 */
	public function restamp(string $learnerUserId, ?DateTimeImmutable $now = null): bool {
		if ($learnerUserId === '') {
			return false;
		}

		try {
			$profile = $this->first(schema: 'learner-profile', filters: ['ncUserId' => $learnerUserId]);
			if ($profile === null) {
				return false;
			}

			$copies = $this->copies(learnerUserId: $learnerUserId, now: $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
			if (self::differs(profile: $profile, copies: $copies) === false) {
				return false;
			}

			$uuid   = (string) ($profile['id'] ?? ($profile['uuid'] ?? ''));
			$object = array_merge($profile, $copies);
			unset($object['@self'], $object['id']);
			$this->objectService->saveObject(
				object: $object,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: 'learner-profile'), uuid: $uuid),
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExamProgressRestamp] The exam progress of {user} could not be written: {msg}',
				['user' => $learnerUserId, 'msg' => $exception->getMessage()]
			);
			return false;
		}//end try
	}//end restamp()

	/**
	 * The derived copies for one student.
	 *
	 * @param string            $learnerUserId The student.
	 * @param DateTimeImmutable $now           The moment.
	 *
	 * @return array<string, mixed>
	 */
	private function copies(string $learnerUserId, DateTimeImmutable $now): array {
		$enrolments = $this->rows(schema: 'enrolment', filters: ['learnerId' => $learnerUserId]);
		$plan       = [];
		$cohortIds  = [];
		foreach ($enrolments as $enrolment) {
			if (in_array($enrolment['lifecycle'] ?? null, ['withdrawn', 'failed'], true) === true) {
				continue;
			}

			if (is_string($enrolment['cohortId'] ?? null) === true && $enrolment['cohortId'] !== '') {
				$cohortIds[] = $enrolment['cohortId'];
			}

			if ($plan === [] && is_string($enrolment['programmeId'] ?? null) === true && $enrolment['programmeId'] !== '') {
				$plan = $this->planOf(programmeId: $enrolment['programmeId']);
			}
		}

		$entries  = $this->rows(schema: 'grade-entry', filters: ['learnerId' => $learnerUserId]);
		$sittings = $this->plannedSittings(cohortIds: $cohortIds);

		$keuzedelen = new KeuzedeelStore(objectService: $this->objectService);
		$line       = (new KeuzedeelRules())->line(
			offers: $keuzedelen->offersFor(learnerUserId: $learnerUserId),
			mine: $keuzedelen->choices(filters: ['learnerId' => $learnerUserId]),
			now: $now
		);

		return $this->progress->derive(plan: $plan, entries: $entries, sittings: $sittings, now: $now) + ['keuzedeelLabel' => $line];
	}//end copies()

	/**
	 * The planned sittings of the cohorts, each with its exam code.
	 *
	 * @param list<string> $cohortIds The student's cohorts.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function plannedSittings(array $cohortIds): array {
		if ($cohortIds === []) {
			return [];
		}

		$sittings = [];
		foreach ($this->rows(schema: 'exam-sitting', filters: ['lifecycle' => 'planned']) as $sitting) {
			if (array_intersect((array) ($sitting['cohortIds'] ?? []), $cohortIds) !== []) {
				$sitting['examCode'] = $this->examCode(assessmentId: (string) ($sitting['assessmentId'] ?? ''));
				$sittings[] = $sitting;
			}
		}

		return $sittings;
	}//end plannedSittings()

	/**
	 * The curriculum plan of a programme.
	 *
	 * @param string $programmeId The programme.
	 *
	 * @return array<string, mixed>
	 */
	private function planOf(string $programmeId): array {
		$programme = $this->first(schema: 'programme', filters: [], ids: [$programmeId]);
		$planId    = $programme['curriculumPlanId'] ?? null;
		if (is_string($planId) === false || $planId === '') {
			return [];
		}

		return $this->first(schema: 'curriculum-plan', filters: [], ids: [$planId]) ?? [];
	}//end planOf()

	/**
	 * The code a sitting's exam reads as: the assessment's title.
	 *
	 * @param string $assessmentId The assessment.
	 *
	 * @return string
	 */
	private function examCode(string $assessmentId): string {
		if ($assessmentId === '') {
			return '';
		}

		return trim((string) (($this->first(schema: 'assessment', filters: [], ids: [$assessmentId]) ?? [])['title'] ?? ''));
	}//end examCode()

	/**
	 * Whether any copy differs from the profile.
	 *
	 * @param array<string, mixed> $profile The profile.
	 * @param array<string, mixed> $copies  The derived copies.
	 *
	 * @return bool
	 */
	private static function differs(array $profile, array $copies): bool {
		foreach ($copies as $key => $value) {
			if (($profile[$key] ?? null) !== $value) {
				return true;
			}
		}

		return false;
	}//end differs()

	/**
	 * The first row of a schema matching the filters (and ids).
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 * @param list<string>         $ids     Restrict to these ids.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(string $schema, array $filters, array $ids = []): ?array {
		foreach ($this->rows(schema: $schema, filters: $filters, ids: $ids) as $row) {
			if ($ids === [] || in_array((string) ($row['id'] ?? ($row['uuid'] ?? '')), $ids, true) === true) {
				return $row;
			}
		}

		return null;
	}//end first()

	/**
	 * The rows of a schema matching the filters, as arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 * @param list<string>         $ids     Restrict to these ids.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, array $filters, array $ids = []): array {
		$config = ['filters' => ['register' => self::REGISTER, 'schema' => $schema] + $filters, 'limit' => 5000];
		if ($ids !== []) {
			$config['ids'] = $ids;
		}

		$rows = [];
		foreach ($this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false) as $row) {
			$data = $row;
			if (is_array($row) === false) {
				$data = (array) $row->jsonSerialize();
			}

			$matches = true;
			foreach ($filters as $field => $value) {
				if (($data[$field] ?? null) !== $value) {
					$matches = false;
				}
			}

			if ($matches === true) {
				$rows[] = $data;
			}
		}

		return $rows;
	}//end rows()
}//end class
