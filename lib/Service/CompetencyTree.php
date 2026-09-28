<?php

/**
 * Learniq Competency Tree
 *
 * Pure reading of one framework's goal tree: the active nodes in sibling
 * order, the leaf goals depth first, and the competency-year-scope read rule
 * (an empty `applicableYears` or a null `subjectId` inherits the nearest
 * ancestor's value). Year labels are compared in canonical form: trimmed,
 * single spaces, lower case.
 *
 * Consumed by CurriculumCoverageCalculator.
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
 * @spec openspec/changes/competency-year-scope/specs/competency/spec.md#requirement-readers-resolve-an-empty-year-or-subject-from-the-nearest-ancestor
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Builds and reads a framework's active goal tree.
 *
 * The tree is an array with `nodes` (id => goal row), `children`
 * (id => child ids in sibling order) and `roots` (ids in sibling order).
 *
 * @spec openspec/changes/competency-year-scope/specs/competency/spec.md#requirement-readers-resolve-an-empty-year-or-subject-from-the-nearest-ancestor
 */
class CompetencyTree {

	/**
	 * Lifecycle states that take a goal or a referencing row out of coverage.
	 */
	public const INACTIVE_STATES = ['archived', 'retired'];

	/**
	 * Build the tree of non-archived goals, children in sibling order.
	 *
	 * @param array<int, array<string, mixed>> $goals The framework's goals.
	 *
	 * @return array<string, mixed> The tree (`nodes`, `children`, `roots`).
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
	 */
	public function build(array $goals): array {
		$nodes = [];
		foreach ($goals as $goal) {
			$goalId = (string) ($goal['id'] ?? '');
			if ($goalId !== '' && $this->isActive(row: $goal) === true) {
				$nodes[$goalId] = $goal;
			}
		}

		uasort($nodes, fn (array $left, array $right): int => $this->siblingOrder(left: $left, right: $right));

		$children = [];
		$roots    = [];
		foreach ($nodes as $goalId => $goal) {
			$parent = (string) ($goal['parentId'] ?? '');
			if ($parent !== '' && $parent !== (string) $goalId && isset($nodes[$parent]) === true) {
				$children[$parent][] = (string) $goalId;
				continue;
			}

			$roots[] = (string) $goalId;
		}

		return ['nodes' => $nodes, 'children' => $children, 'roots' => $roots];
	}//end build()

	/**
	 * The leaf goal ids, depth first, so they list under their domains.
	 *
	 * @param array<string, mixed> $tree A tree from build().
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
	 */
	public function leaves(array $tree): array {
		$leaves  = [];
		$stack   = array_reverse((array) ($tree['roots'] ?? []));
		$visited = [];
		while ($stack !== []) {
			$goalId = (string) array_pop($stack);
			if (isset($visited[$goalId]) === true) {
				continue;
			}

			$visited[$goalId] = true;
			$children         = (array) ($tree['children'][$goalId] ?? []);
			if ($children === []) {
				$leaves[] = $goalId;
				continue;
			}

			foreach (array_reverse($children) as $child) {
				$stack[] = $child;
			}
		}

		return $leaves;
	}//end leaves()

	/**
	 * The goal's own non-empty years, else the nearest ancestor's, canonical.
	 *
	 * @param array<string, mixed> $tree   A tree from build().
	 * @param string               $goalId The goal.
	 *
	 * @return array<int, string> Empty when the goal applies to every year.
	 *
	 * @spec openspec/changes/competency-year-scope/specs/competency/spec.md#requirement-readers-resolve-an-empty-year-or-subject-from-the-nearest-ancestor
	 */
	public function effectiveYears(array $tree, string $goalId): array {
		foreach ($this->lineage(tree: $tree, goalId: $goalId) as $node) {
			$years = array_map(fn (mixed $label): string => $this->canonicalYear(label: $label), (array) ($node['applicableYears'] ?? []));
			$years = array_values(array_unique(array_filter($years, static fn (string $year): bool => $year !== '')));
			if ($years !== []) {
				return $years;
			}
		}

		return [];
	}//end effectiveYears()

	/**
	 * The goal's own subject, else the nearest ancestor's, else null.
	 *
	 * @param array<string, mixed> $tree   A tree from build().
	 * @param string               $goalId The goal.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/competency-year-scope/specs/competency/spec.md#requirement-readers-resolve-an-empty-year-or-subject-from-the-nearest-ancestor
	 */
	public function effectiveSubject(array $tree, string $goalId): ?string {
		foreach ($this->lineage(tree: $tree, goalId: $goalId) as $node) {
			$subject = $node['subjectId'] ?? null;
			if (is_string($subject) === true && $subject !== '') {
				return $subject;
			}
		}

		return null;
	}//end effectiveSubject()

	/**
	 * A year label in canonical form: trimmed, single spaces, lower case.
	 *
	 * @param mixed $label The raw label.
	 *
	 * @return string '' for anything that is not a usable label.
	 *
	 * @spec openspec/changes/competency-year-scope/specs/competency/spec.md#requirement-readers-resolve-an-empty-year-or-subject-from-the-nearest-ancestor
	 */
	public function canonicalYear(mixed $label): string {
		if (is_string($label) === false) {
			return '';
		}

		return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));
	}//end canonicalYear()

	/**
	 * Whether a goal or referencing row is neither archived nor retired.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
	 */
	public function isActive(array $row): bool {
		return in_array(needle: ($row['lifecycle'] ?? null), haystack: self::INACTIVE_STATES, strict: true) === false;
	}//end isActive()

	/**
	 * Compare two sibling goals by `order`, then `code`.
	 *
	 * @param array<string, mixed> $left  One goal.
	 * @param array<string, mixed> $right The other goal.
	 *
	 * @return int
	 */
	private function siblingOrder(array $left, array $right): int {
		$byOrder = ((int) ($left['order'] ?? PHP_INT_MAX)) <=> ((int) ($right['order'] ?? PHP_INT_MAX));
		if ($byOrder !== 0) {
			return $byOrder;
		}

		return strnatcasecmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
	}//end siblingOrder()

	/**
	 * The goal and its active ancestors, nearest first, cycle-safe.
	 *
	 * @param array<string, mixed> $tree   A tree from build().
	 * @param string               $goalId The goal.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lineage(array $tree, string $goalId): array {
		$nodes   = (array) ($tree['nodes'] ?? []);
		$lineage = [];
		$seen    = [];
		$current = $goalId;
		while ($current !== '' && isset($nodes[$current]) === true && isset($seen[$current]) === false) {
			$seen[$current] = true;
			$lineage[]      = $nodes[$current];
			$current        = (string) ($nodes[$current]['parentId'] ?? '');
		}

		return $lineage;
	}//end lineage()
}//end class
