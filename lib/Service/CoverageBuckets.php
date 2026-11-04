<?php

/**
 * Learniq curriculum coverage buckets.
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
 * @spec openspec/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The buckets a curriculum coverage report is split into: every year a goal
 * applies to (and all years), crossed with all subjects, each subject and
 * no subject.
 *
 * @spec openspec/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
 */
final class CoverageBuckets {

	/**
	 * Every year × subject bucket, total first.
	 *
	 * @param array<string, array{years: array<int, string>, subject: string|null}> $scope Per goal scope.
	 *
	 * @return array<int, array{year: string|null, subjectScope: string, subjectId: string|null}>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
	 */
	public function forScope(array $scope): array {
		$buckets = [];
		foreach (array_merge([null], self::scopeYears(scope: $scope)) as $year) {
			foreach (self::subjectScopes(scope: $scope) as $subjectScope) {
				$buckets[] = array_merge(['year' => $year], $subjectScope);
			}
		}

		return $buckets;
	}//end forScope()

	/**
	 * Every year a goal applies to, once each, in natural order.
	 *
	 * @param array<string,array{years: list<string>, subject: string|null}> $scope Each leaf goal's years and subject.
	 *
	 * @return list<string>
	 */
	private static function scopeYears(array $scope): array {
		$years = [];
		foreach ($scope as $goal) {
			$years = array_merge($years, $goal['years']);
		}

		$years = array_values(array_unique($years));
		usort($years, 'strnatcasecmp');

		return $years;
	}//end scopeYears()

	/**
	 * The subject scopes: all, each subject a goal has, and none when a goal has no subject.
	 *
	 * @param array<string,array{years: list<string>, subject: string|null}> $scope Each leaf goal's years and subject.
	 *
	 * @return list<array{subjectScope: string, subjectId: string|null}>
	 */
	private static function subjectScopes(array $scope): array {
		$subjects = array_column($scope, 'subject');
		$named    = array_values(array_unique(array_filter($subjects, static fn (?string $subject): bool => $subject !== null)));
		sort($named);

		$subjectScopes = [['subjectScope' => 'all', 'subjectId' => null]];
		foreach ($named as $subject) {
			$subjectScopes[] = ['subjectScope' => 'subject', 'subjectId' => $subject];
		}

		if (in_array(null, $subjects, true) === true) {
			$subjectScopes[] = ['subjectScope' => 'none', 'subjectId' => null];
		}

		return $subjectScopes;
	}//end subjectScopes()

	/**
	 * Whether a goal falls in a bucket.
	 *
	 * @param array{years: array<int, string>, subject: string|null}                  $goal   The goal's scope.
	 * @param array{year: string|null, subjectScope: string, subjectId: string|null} $bucket The bucket.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
	 */
	public function contains(array $goal, array $bucket): bool {
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
	}//end contains()

	/**
	 * Whether a bucket is the all-years, all-subjects total.
	 *
	 * @param array{year: string|null, subjectScope: string, subjectId: string|null} $bucket The bucket.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
	 */
	public function isTotal(array $bucket): bool {
		return $bucket['year'] === null && $bucket['subjectScope'] === 'all';
	}//end isTotal()

}//end class
