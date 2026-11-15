<?php

/**
 * Contract of CompetencyAttainmentRollup
 *
 * The public surface of CompetencyAttainmentRollup that other components call, so they depend
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
 * Contract of CompetencyAttainmentRollup.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CompetencyAttainmentRollupInterface {

	/**
	 * A WerkprocesAssessment was created.
	 */
	public const WERKPROCES_CREATED = 'werkproces-created';

	/**
	 * A GradeEntry was published.
	 */
	public const GRADE_ENTRY_PUBLISHED = 'grade-entry-published';

	/**
	 * A WerkprocesAssessment was confirmed.
	 */
	public const WERKPROCES_CONFIRMED = 'werkproces-confirmed';

	/**
	 * Run the roll-up owed for one event.
	 *
	 * @param string $kind One of the kind constants.
	 * @param array<string, mixed> $object The object as it was saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
	 */
	public function run(string $kind, array $object): void;
}//end interface
