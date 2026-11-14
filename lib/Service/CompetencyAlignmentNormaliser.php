<?php

/**
 * Learniq Competency Alignment Normaliser
 *
 * The list logic behind `competencyAlignments` (goal-alignment-depth), with no
 * I/O so every rule is unit-tested without mocks:
 *
 *   - idsFromAlignments(): the flat `competencyIds` a list of alignments
 *     derives, in alignment order, without duplicates.
 *   - alignmentsFollowIds(): the alignments after only `competencyIds` was
 *     edited (kept goals keep their depth, a new goal gets depth null, a
 *     removed goal drops out).
 *   - problem(): why a list of alignments may not be saved, or null.
 *   - effectiveAlignments(): the read rule every consumer uses (a row with no
 *     alignments reads its `competencyIds` as alignments without depth; a
 *     depth the framework no longer knows reads as null).
 *
 * Consumed by:
 *   - CompetencyAlignmentListener (pre-save sync and refusal)
 *   - the curriculum coverage rollup (effectiveAlignments)
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
 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\CompetencyAlignmentNormaliserInterface;
use OCA\Learniq\Support\TextValue;

/**
 * Derives, syncs, checks and reads competency alignments.
 *
 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
 */
class CompetencyAlignmentNormaliser implements CompetencyAlignmentNormaliserInterface {

	/**
	 * Normalise a raw `competencyAlignments` value to a list of
	 * `{competencyId, depth}` pairs. Entries without a usable goal id are
	 * kept with an empty id so problem() can name them; a blank depth is null.
	 *
	 * @param mixed $raw The stored or submitted `competencyAlignments` value.
	 *
	 * @return array<int, array{competencyId: string, depth: string|null}>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-lessons-courses-assignments-and-assessments-align-to-goals-with-a-depth
	 */
	public function normalise(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		return array_map(fn (mixed $entry): array => $this->alignmentOf(entry: $entry), array_values($raw));
	}//end normalise()

	/**
	 * One raw entry as a `{competencyId, depth}` pair.
	 *
	 * @param mixed $entry The raw entry.
	 *
	 * @return array{competencyId: string, depth: string|null}
	 */
	private function alignmentOf(mixed $entry): array {
		if (is_array($entry) === false) {
			$entry = [];
		}

		$depth = ($entry['depth'] ?? null);
		if (is_string($depth) === false || trim($depth) === '') {
			$depth = null;
		}

		return [
			'competencyId' => $this->stringOrEmpty(value: ($entry['competencyId'] ?? '')),
			'depth'        => $depth,
		];
	}//end alignmentOf()

	/**
	 * The flat goal list a list of alignments derives: alignment order, no
	 * duplicates, no empty ids.
	 *
	 * @param array<int, array{competencyId: string, depth: string|null}> $alignments Normalised alignments.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
	 */
	public function idsFromAlignments(array $alignments): array {
		$ids = [];
		foreach ($alignments as $alignment) {
			$goal = $alignment['competencyId'];
			if ($goal !== '' && in_array(needle: $goal, haystack: $ids, strict: true) === false) {
				$ids[] = $goal;
			}
		}

		return $ids;
	}//end idsFromAlignments()

	/**
	 * The alignments after only the flat list was edited: a goal still listed
	 * keeps its alignment (and depth), a newly listed goal gets depth null, a
	 * goal no longer listed drops out. Order follows the flat list.
	 *
	 * @param array<int, array{competencyId: string, depth: string|null}> $alignments Stored, normalised alignments.
	 * @param mixed                                                       $ids        The new `competencyIds` value.
	 *
	 * @return array<int, array{competencyId: string, depth: string|null}>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
	 */
	public function alignmentsFollowIds(array $alignments, mixed $ids): array {
		$depthByGoal = [];
		foreach ($alignments as $alignment) {
			if (array_key_exists($alignment['competencyId'], $depthByGoal) === false) {
				$depthByGoal[$alignment['competencyId']] = $alignment['depth'];
			}
		}

		$followed = [];
		foreach ($this->flatIds(raw: $ids) as $goal) {
			$followed[] = [
				'competencyId' => $goal,
				'depth'        => ($depthByGoal[$goal] ?? null),
			];
		}

		return $followed;
	}//end alignmentsFollowIds()

	/**
	 * Why a list of alignments may not be saved, or null when it may.
	 *
	 * `$goals` maps each resolvable goal id to its code and the level ids of
	 * its framework; a goal id missing from the map did not resolve.
	 *
	 * @param array<int, array{competencyId: string, depth: string|null}> $alignments Normalised alignments.
	 * @param array<string, array{code: string, levels: array<int, string>}> $goals   Resolved goals by id.
	 *
	 * @return string|null The refusal message, or null.
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-a-depth-the-goals-framework-does-not-know-is-refused
	 */
	public function problem(array $alignments, array $goals): ?string {
		$seen = [];
		foreach ($alignments as $alignment) {
			$goal = $alignment['competencyId'];
			if ($goal === '' || isset($goals[$goal]) === false) {
				return 'A goal alignment points at a goal that does not exist. Pick an existing goal.';
			}

			if (isset($seen[$goal]) === true) {
				return sprintf('Goal %s is aligned twice. Keep one alignment per goal.', $goals[$goal]['code']);
			}

			$seen[$goal] = true;
			$problem     = $this->depthProblem(depth: $alignment['depth'], goal: $goals[$goal]);
			if ($problem !== null) {
				return $problem;
			}
		}

		return null;
	}//end problem()

	/**
	 * Why a depth may not be saved on a goal, or null when it may.
	 *
	 * @param string|null                                  $depth The depth.
	 * @param array{code: string, levels: array<int, string>} $goal  The goal's code and its framework's level ids.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private function depthProblem(?string $depth, array $goal): ?string {
		if ($depth === null || in_array(needle: $depth, haystack: $goal['levels'], strict: true) === true) {
			return null;
		}

		return sprintf(
			'Depth "%s" is not a level of the framework of goal %s. Use one of: %s, or leave it empty.',
			$depth,
			$goal['code'],
			implode(separator: ', ', array: $goal['levels'])
		);
	}//end depthProblem()

	/**
	 * The effective alignments of a Lesson, Course, Assignment or Assessment
	 * row: its alignments when it has any, else its flat list with depth null.
	 * With `$levelsByGoal` given, a depth the goal's framework no longer knows
	 * reads as null; the link itself is never dropped.
	 *
	 * @param array<string, mixed>              $row          The object's data.
	 * @param array<string, array<int, string>> $levelsByGoal Level ids per goal id, when known.
	 *
	 * @return array<int, array{competencyId: string, depth: string|null}>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-readers-treat-a-flat-only-row-as-alignments-without-depth
	 */
	public function effectiveAlignments(array $row, array $levelsByGoal=[]): array {
		$effective = [];
		$seen      = [];
		foreach ($this->namedAlignments(row: $row) as $alignment) {
			$goal = $alignment['competencyId'];
			if (isset($seen[$goal]) === false) {
				$seen[$goal] = true;
				$effective[] = ['competencyId' => $goal, 'depth' => $this->knownDepth(alignment: $alignment, levels: ($levelsByGoal[$goal] ?? null))];
			}
		}

		return $effective;
	}//end effectiveAlignments()

	/**
	 * A row's alignments with a goal id, else its flat list with depth null.
	 *
	 * @param array<string, mixed> $row The object's data.
	 *
	 * @return array<int, array{competencyId: string, depth: string|null}>
	 */
	private function namedAlignments(array $row): array {
		$alignments = array_filter(
			$this->normalise(raw: ($row['competencyAlignments'] ?? [])),
			static fn (array $alignment): bool => $alignment['competencyId'] !== ''
		);
		if ($alignments === []) {
			return $this->alignmentsFollowIds(alignments: [], ids: ($row['competencyIds'] ?? []));
		}

		return array_values($alignments);
	}//end namedAlignments()

	/**
	 * An alignment's depth, or null when the goal's known levels no longer hold it.
	 *
	 * @param array{competencyId: string, depth: string|null} $alignment The alignment.
	 * @param array<int, string>|null                         $levels    The goal's level ids, when known.
	 *
	 * @return string|null
	 */
	private function knownDepth(array $alignment, ?array $levels): ?string {
		if ($levels !== null && in_array(needle: $alignment['depth'], haystack: $levels, strict: true) === false) {
			return null;
		}

		return $alignment['depth'];
	}//end knownDepth()

	/**
	 * The distinct, non-empty string ids of a raw `competencyIds` value.
	 *
	 * @param mixed $raw The `competencyIds` value.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-readers-treat-a-flat-only-row-as-alignments-without-depth
	 */
	public function flatIds(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$ids = [];
		foreach ($raw as $goal) {
			$goal = $this->stringOrEmpty(value: $goal);
			if ($goal !== '' && in_array(needle: $goal, haystack: $ids, strict: true) === false) {
				$ids[] = $goal;
			}
		}

		return $ids;
	}//end flatIds()

	/**
	 * A trimmed string, or '' for anything that is not a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string
	 */
	private function stringOrEmpty(mixed $value): string {
		return (new TextValue())->trimmedOrEmpty(value: $value);
	}//end stringOrEmpty()
}//end class
