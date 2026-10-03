<?php

/**
 * Learniq Submission Mark Reader
 *
 * Answers which SubmissionMark rows of one submission a caller may read
 * (assignments-double-marking). A marker whose own mark is still a draft sees
 * only that draft; after handing in their own mark they see every mark. A
 * caller who sees all (compliance officers, team leads, admins) sees every
 * mark at any time. The rule depends on a second row, so the register's
 * `authorization` block cannot express it; this reader is where it lives, for
 * the API and the screen alike.
 *
 * The summary (allocated, handed in, average and highest of the handed-in
 * grades) is returned only with the full set, so a draft marker cannot read
 * another marker's grade from an average of two.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/assignments/spec.md#requirement-a-marker-sees-other-marks-only-after-submitting-their-own
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads the marks of one submission for one caller.
 *
 * @spec openspec/specs/assignments/spec.md#requirement-a-marker-sees-other-marks-only-after-submitting-their-own
 */
class SubmissionMarkReader {

	private const REGISTER = 'learniq';
	private const MARK_SCHEMA = 'submission-mark';
	private const SUBMITTED = 'submitted';
	private const READ_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The marks the caller may read, with the summary when they may read all.
	 *
	 * @param array<string, mixed> $submission The submission.
	 * @param string               $userId     The caller's Nextcloud user id.
	 * @param bool                 $seesAll    Whether the caller reads every mark at any time.
	 *
	 * @return array{allowed: bool, marks: list<array<string, mixed>>, ownMark: array<string, mixed>|null, complete: bool, summary: array|null}
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-the-second-marker-cannot-peek
	 */
	public function forCaller(array $submission, string $userId, bool $seesAll): array {
		$marks = $this->marks(submissionId: (string)($submission['id'] ?? ''));
		$own = null;
		foreach ($marks as $mark) {
			if (($mark['markerId'] ?? '') === $userId) {
				$own = $mark;
			}
		}

		if ($own === null && $seesAll === false) {
			return ['allowed' => false, 'marks' => [], 'ownMark' => null, 'complete' => false, 'summary' => null];
		}

		$summary = $this->summary(marks: $marks);
		$complete = $summary['allocated'] > 0 && $summary['submitted'] === $summary['allocated'];
		$ownSubmitted = $own !== null && ($own['lifecycle'] ?? '') === self::SUBMITTED;

		if ($seesAll === false && $ownSubmitted === false) {
			return ['allowed' => true, 'marks' => [$own], 'ownMark' => $own, 'complete' => false, 'summary' => null];
		}

		return ['allowed' => true, 'marks' => $marks, 'ownMark' => $own, 'complete' => $complete, 'summary' => $summary];
	}//end forCaller()

	/**
	 * Count, average and highest over the handed-in marks.
	 *
	 * @param list<array<string, mixed>> $marks Every mark of the submission.
	 *
	 * @return array{allocated: int, submitted: int, average: float|null, highest: float|null}
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-one-person-sets-the-final-grade-once-every-mark-is-in
	 */
	public function summary(array $marks): array {
		$grades = [];
		$submitted = 0;
		foreach ($marks as $mark) {
			if (($mark['lifecycle'] ?? '') !== self::SUBMITTED) {
				continue;
			}

			$submitted++;
			if (is_numeric($mark['proposedGrade'] ?? null) === true) {
				$grades[] = (float)$mark['proposedGrade'];
			}
		}

		$average = null;
		$highest = null;
		if ($grades !== []) {
			$average = round(array_sum($grades) / count($grades), 2);
			$highest = max($grades);
		}

		return ['allocated' => count($marks), 'submitted' => $submitted, 'average' => $average, 'highest' => $highest];
	}//end summary()

	/**
	 * Every mark of a submission, read as the system after the caller's check.
	 *
	 * @param string $submissionId The submission's uuid.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function marks(string $submissionId): array {
		if ($submissionId === '') {
			return [];
		}

		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::MARK_SCHEMA,
					'submissionId' => $submissionId,
				],
				'limit' => self::READ_LIMIT,
			],
			_rbac: false
		);

		$marks = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				unset($row['@self']['relations']);
				$marks[] = $row;
			}
		}

		return $marks;
	}//end marks()
}//end class
