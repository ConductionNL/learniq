<?php

/**
 * Contract of PortalResultReader
 *
 * The public surface of PortalResultReader that other components call, so they depend
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

use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Contract of PortalResultReader.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalResultReaderInterface {

	/**
	 * The pupil's result on one attempt.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $attemptId The attempt.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it
	 */
	public function result(PortalLearner $learner, string $attemptId): PortalOutcome;
}//end interface
