<?php

/**
 * Learniq Curriculum Coverage Calculator
 *
 * Pure counting behind CurriculumCoverage (curriculum-coverage-rollup): given
 * one framework, its goals and the rows that align to them, it returns the
 * coverage rows per year label (plus all years) and per subject (plus all
 * subjects and no subject). No I/O, so every rule is unit-tested directly.
 *
 * Rules (openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md):
 *   - only leaf goals count; archived goals are left out;
 *   - planned = a non-retired Lesson or non-archived Course aligns to the goal,
 *     assessed = a non-archived Assignment or Assessment does, both read
 *     through CompetencyAlignmentNormaliser::effectiveAlignments();
 *   - per goal the deepest depth per kind wins by the framework's level order;
 *   - years and subject resolve from the nearest ancestor when empty
 *     (competency-year-scope read rule); a goal with no years is in every year;
 *   - the all-years, all-subjects total row always exists.
 *
 * Consumed by CurriculumCoverageRollup.
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
 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Computes CurriculumCoverage rows for one framework.
 *
 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
 */
class CurriculumCoverageCalculator {

	/**
	 * Constructor.
	 *
	 * @param CompetencyAlignmentNormaliser $normaliser The alignment read rule.
	 * @param CompetencyTree                $tree       The goal tree reader.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CompetencyAlignmentNormaliser $normaliser,
		private readonly CompetencyTree $tree,
	) {
	}//end __construct()

	/**
	 * Compute every coverage row of one framework.
	 *
	 * @param array<string, mixed>             $framework The CompetencyFramework row.
	 * @param array<int, array<string, mixed>> $goals     The framework's Competency rows.
	 * @param array<int, array<string, mixed>> $planned   Lesson and Course rows that may align to them.
	 * @param array<int, array<string, mixed>> $assessed  Assignment and Assessment rows that may align to them.
	 * @param string                           $now       ISO timestamp for lastRecomputedAt.
	 *
	 * @return array<int, array<string, mixed>> Coverage rows without ids, total row first.
	 *
	 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-coverage-is-bucketed-by-year-and-subject-with-totals
	 */
	public function compute(array $framework, array $goals, array $planned, array $assessed, string $now): array {
		$levels = $this->levelRanks(framework: $framework);
		$tree   = $this->tree->build(goals: $goals);
		$leaves = $this->tree->leaves(tree: $tree);
		$status = $this->goalStatus(
			leaves: $leaves,
			planned: $this->tally(rows: $planned, leaves: $leaves, levels: $levels),
			assessed: $this->tally(rows: $assessed, leaves: $leaves, levels: $levels)
		);

		$scope = [];
		foreach ($leaves as $goalId) {
			$scope[$goalId] = [
				'years'   => $this->tree->effectiveYears(tree: $tree, goalId: $goalId),
				'subject' => $this->tree->effectiveSubject(tree: $tree, goalId: $goalId),
			];
		}

		$rows = [];
		foreach ($this->buckets(scope: $scope) as $bucket) {
			$inScope = array_values(
				array_filter(
					$leaves,
					fn (string $goalId): bool => $this->inBucket(goal: $scope[$goalId], bucket: $bucket)
				)
			);
			if ($inScope === [] && $this->isTotal(bucket: $bucket) === false) {
				continue;
			}

			$rows[] = $this->row(framework: $framework, bucket: $bucket, goalIds: $inScope, status: $status, levels: $levels, now: $now);
		}

		return $rows;
	}//end compute()

	/**
	 * The key that identifies a coverage row within its framework.
	 *
	 * @param array<string, mixed> $row A coverage row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
	 */
	public function bucketKey(array $row): string {
		return implode(
			separator: '|',
			array: [
				(string) ($row['frameworkId'] ?? ''),
				(string) ($row['year'] ?? '*'),
				(string) ($row['subjectScope'] ?? ''),
				(string) ($row['subjectId'] ?? ''),
			]
		);
	}//end bucketKey()

	/**
	 * Level id to rank, by `order` (array position when missing).
	 *
	 * @param array<string, mixed> $framework The framework row.
	 *
	 * @return array<string, int>
	 */
	private function levelRanks(array $framework): array {
		$levels = [];
		foreach (array_values((array) ($framework['proficiencyLevels'] ?? [])) as $position => $level) {
			if (is_array($level) === true && is_string($level['levelId'] ?? null) === true) {
				$levels[$level['levelId']] = (int) ($level['order'] ?? $position);
			}
		}

		return $levels;
	}//end levelRanks()

	/**
	 * Per leaf goal: how many active rows align to it, and the deepest depth.
	 *
	 * @param array<int, array<string, mixed>> $rows   Referencing rows of one kind.
	 * @param array<int, string>               $leaves The leaf goal ids.
	 * @param array<string, int>               $levels Level ranks.
	 *
	 * @return array<string, array{refs: int, depth: string|null}>
	 */
	private function tally(array $rows, array $leaves, array $levels): array {
		$isLeaf       = array_fill_keys($leaves, true);
		$levelsByGoal = array_fill_keys($leaves, array_keys($levels));
		$tally        = [];
		foreach ($rows as $row) {
			if ($this->tree->isActive(row: $row) === false) {
				continue;
			}

			foreach ($this->normaliser->effectiveAlignments(row: $row, levelsByGoal: $levelsByGoal) as $alignment) {
				$goalId = $alignment['competencyId'];
				if (isset($isLeaf[$goalId]) === false) {
					continue;
				}

				$current        = ($tally[$goalId] ?? ['refs' => 0, 'depth' => null]);
				$tally[$goalId] = [
					'refs'  => ($current['refs'] + 1),
					'depth' => $this->deeper(first: $current['depth'], second: $alignment['depth'], levels: $levels),
				];
			}
		}

		return $tally;
	}//end tally()

	/**
	 * The deeper of two depths; a real level beats null.
	 *
	 * @param string|null        $first  One depth.
	 * @param string|null        $second The other depth.
	 * @param array<string, int> $levels Level ranks.
	 *
	 * @return string|null
	 */
	private function deeper(?string $first, ?string $second, array $levels): ?string {
		if ($first === null) {
			return $second;
		}

		if ($second === null) {
			return $first;
		}

		if (($levels[$second] ?? -1) > ($levels[$first] ?? -1)) {
			return $second;
		}

		return $first;
	}//end deeper()

	/**
	 * Merge the two tallies into one status per leaf goal.
	 *
	 * @param array<int, string>                                  $leaves   The leaf goal ids.
	 * @param array<string, array{refs: int, depth: string|null}> $planned  Planned tally.
	 * @param array<string, array{refs: int, depth: string|null}> $assessed Assessed tally.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function goalStatus(array $leaves, array $planned, array $assessed): array {
		$status = [];
		foreach ($leaves as $goalId) {
			$status[$goalId] = [
				'competencyId'  => $goalId,
				'planned'       => isset($planned[$goalId]),
				'assessed'      => isset($assessed[$goalId]),
				'plannedDepth'  => ($planned[$goalId]['depth'] ?? null),
				'assessedDepth' => ($assessed[$goalId]['depth'] ?? null),
				'plannedRefs'   => ($planned[$goalId]['refs'] ?? 0),
				'assessedRefs'  => ($assessed[$goalId]['refs'] ?? 0),
			];
		}

		return $status;
	}//end goalStatus()

	/**
	 * Every year × subject bucket, total first.
	 *
	 * @param array<string, array{years: array<int, string>, subject: string|null}> $scope Per goal scope.
	 *
	 * @return array<int, array{year: string|null, subjectScope: string, subjectId: string|null}>
	 */
	private function buckets(array $scope): array {
		$years    = [];
		$subjects = [];
		$hasNone  = false;
		foreach ($scope as $goal) {
			$years = array_merge($years, $goal['years']);
			if ($goal['subject'] === null) {
				$hasNone = true;
				continue;
			}

			$subjects[] = $goal['subject'];
		}

		$years = array_values(array_unique($years));
		usort($years, 'strnatcasecmp');
		$subjects = array_values(array_unique($subjects));
		sort($subjects);

		$subjectScopes = [['subjectScope' => 'all', 'subjectId' => null]];
		foreach ($subjects as $subject) {
			$subjectScopes[] = ['subjectScope' => 'subject', 'subjectId' => $subject];
		}

		if ($hasNone === true) {
			$subjectScopes[] = ['subjectScope' => 'none', 'subjectId' => null];
		}

		$buckets = [];
		foreach (array_merge([null], $years) as $year) {
			foreach ($subjectScopes as $subjectScope) {
				$buckets[] = array_merge(['year' => $year], $subjectScope);
			}
		}

		return $buckets;
	}//end buckets()

	/**
	 * Whether a goal falls in a bucket.
	 *
	 * @param array{years: array<int, string>, subject: string|null}                  $goal   The goal's scope.
	 * @param array{year: string|null, subjectScope: string, subjectId: string|null} $bucket The bucket.
	 *
	 * @return bool
	 */
	private function inBucket(array $goal, array $bucket): bool {
		$inYear = ($bucket['year'] === null || $goal['years'] === [] || in_array(needle: $bucket['year'], haystack: $goal['years'], strict: true) === true);
		if ($inYear === false) {
			return false;
		}

		if ($bucket['subjectScope'] === 'all') {
			return true;
		}

		if ($bucket['subjectScope'] === 'none') {
			return $goal['subject'] === null;
		}

		return $goal['subject'] === $bucket['subjectId'];
	}//end inBucket()

	/**
	 * Whether a bucket is the all-years, all-subjects total.
	 *
	 * @param array{year: string|null, subjectScope: string, subjectId: string|null} $bucket The bucket.
	 *
	 * @return bool
	 */
	private function isTotal(array $bucket): bool {
		return $bucket['year'] === null && $bucket['subjectScope'] === 'all';
	}//end isTotal()

	/**
	 * Build one coverage row.
	 *
	 * @param array<string, mixed>                                                   $framework The framework row.
	 * @param array{year: string|null, subjectScope: string, subjectId: string|null} $bucket    The bucket.
	 * @param array<int, string>                                                     $goalIds   Goals in scope, tree order.
	 * @param array<string, array<string, mixed>>                                    $status    Per goal status.
	 * @param array<string, int>                                                     $levels    Level ranks.
	 * @param string                                                                 $now       Timestamp.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $framework, array $bucket, array $goalIds, array $status, array $levels, string $now): array {
		$goals    = array_map(static fn (string $goalId): array => $status[$goalId], $goalIds);
		$planned  = array_values(array_filter($goals, static fn (array $goal): bool => $goal['planned']));
		$assessed = array_values(array_filter($goals, static fn (array $goal): bool => $goal['assessed']));
		$gap      = array_values(array_filter($goals, static fn (array $goal): bool => $goal['planned'] && $goal['assessed'] === false));
		$none     = array_values(array_filter($goals, static fn (array $goal): bool => $goal['planned'] === false && $goal['assessed'] === false));

		return [
			'frameworkId'             => (string) ($framework['id'] ?? ''),
			'year'                    => $bucket['year'],
			'subjectScope'            => $bucket['subjectScope'],
			'subjectId'               => $bucket['subjectId'],
			'goalCount'               => count($goals),
			'plannedCount'            => count($planned),
			'assessedCount'           => count($assessed),
			'plannedNotAssessedCount' => count($gap),
			'uncoveredCount'          => count($none),
			'plannedPercent'          => $this->percent(part: count($planned), whole: count($goals)),
			'assessedPercent'         => $this->percent(part: count($assessed), whole: count($goals)),
			'plannedByDepth'          => $this->byDepth(goals: $planned, key: 'plannedDepth', levels: $levels),
			'assessedByDepth'         => $this->byDepth(goals: $assessed, key: 'assessedDepth', levels: $levels),
			'uncoveredIds'            => array_column($none, 'competencyId'),
			'plannedNotAssessedIds'   => array_column($gap, 'competencyId'),
			'goals'                   => $goals,
			'lastRecomputedAt'        => $now,
			'tenant_id'               => ($framework['tenant_id'] ?? null),
		];
	}//end row()

	/**
	 * A share as a percentage with one decimal; 0 for an empty whole.
	 *
	 * @param int $part  The part.
	 * @param int $whole The whole.
	 *
	 * @return float
	 */
	private function percent(int $part, int $whole): float {
		if ($whole === 0) {
			return 0.0;
		}

		return round((100 * $part) / $whole, 1);
	}//end percent()

	/**
	 * Goals per deepest depth, in level order, null last, zero counts left out.
	 *
	 * @param array<int, array<string, mixed>> $goals  Goals of one kind.
	 * @param string                           $key    plannedDepth or assessedDepth.
	 * @param array<string, int>               $levels Level ranks.
	 *
	 * @return array<int, array{depth: string|null, count: int}>
	 */
	private function byDepth(array $goals, string $key, array $levels): array {
		$counts = [];
		foreach ($goals as $goal) {
			$depth          = (string) ($goal[$key] ?? '');
			$counts[$depth] = (($counts[$depth] ?? 0) + 1);
		}

		uksort(
			$counts,
			static function (int|string $left, int|string $right) use ($levels): int {
				$rankLeft  = ($levels[(string) $left] ?? PHP_INT_MAX);
				$rankRight = ($levels[(string) $right] ?? PHP_INT_MAX);
				return $rankLeft <=> $rankRight;
			}
		);

		$result = [];
		foreach ($counts as $depth => $count) {
			$result[] = ['depth' => $this->depthOrNull(depth: (string) $depth), 'count' => $count];
		}

		return $result;
	}//end byDepth()

	/**
	 * A depth key back to a depth: '' means no depth.
	 *
	 * @param string $depth The key.
	 *
	 * @return string|null
	 */
	private function depthOrNull(string $depth): ?string {
		if ($depth === '') {
			return null;
		}

		return $depth;
	}//end depthOrNull()

}//end class
