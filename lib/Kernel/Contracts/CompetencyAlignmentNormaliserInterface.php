<?php

/**
 * Contract of CompetencyAlignmentNormaliser
 *
 * The public surface of CompetencyAlignmentNormaliser that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of CompetencyAlignmentNormaliser.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CompetencyAlignmentNormaliserInterface {

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
	public function alignmentsFollowIds(array $alignments, mixed $ids): array;

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
	public function effectiveAlignments(array $row, array $levelsByGoal=[]): array;

	/**
	 * The distinct, non-empty string ids of a raw `competencyIds` value.
	 *
	 * @param mixed $raw The `competencyIds` value.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-readers-treat-a-flat-only-row-as-alignments-without-depth
	 */
	public function flatIds(mixed $raw): array;

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
	public function idsFromAlignments(array $alignments): array;

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
	public function normalise(mixed $raw): array;

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
	public function problem(array $alignments, array $goals): ?string;
}//end interface
