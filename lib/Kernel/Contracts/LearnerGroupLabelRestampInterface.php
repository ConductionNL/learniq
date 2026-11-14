<?php

/**
 * Contract of LearnerGroupLabelRestamp
 *
 * The public surface of LearnerGroupLabelRestamp that other components call, so they depend
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
 * Contract of LearnerGroupLabelRestamp.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LearnerGroupLabelRestampInterface {

	/**
	 * Write the current group line on one profile, when it differs.
	 *
	 * @param string $learnerRef The learner profile id.
	 *
	 * @return bool Whether the profile was saved.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
	 */
	public function restamp(string $learnerRef): bool;
}//end interface
