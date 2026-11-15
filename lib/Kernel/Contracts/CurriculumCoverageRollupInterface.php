<?php

/**
 * Contract of CurriculumCoverageRollup
 *
 * The public surface of CurriculumCoverageRollup that other components call, so they depend
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
 * Contract of CurriculumCoverageRollup.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CurriculumCoverageRollupInterface {

	/**
	 * Every framework id in the register.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-an-occ-command-fills-coverage-for-existing-data
	 */
	public function allFrameworkIds(): array;

	/**
	 * Whether a framework exists.
	 *
	 * @param string $frameworkId UUID of the CompetencyFramework.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-an-occ-command-fills-coverage-for-existing-data
	 */
	public function frameworkExists(string $frameworkId): bool;

	/**
	 * The distinct framework ids of a set of goals; unknown goals are skipped.
	 *
	 * @param array<int, string> $goalIds Goal UUIDs.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
	 */
	public function frameworksForGoals(array $goalIds): array;

	/**
	 * Recompute one framework's coverage and store what changed.
	 *
	 * @param string $frameworkId UUID of the CompetencyFramework.
	 *
	 * @return array{saved: int, deleted: int, unchanged: int}
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
	 */
	public function recompute(string $frameworkId): array;
}//end interface
