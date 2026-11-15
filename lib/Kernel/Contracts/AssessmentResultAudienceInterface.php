<?php

/**
 * Contract of AssessmentResultAudience
 *
 * The public surface of AssessmentResultAudience that other components call, so they depend
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

use OCA\OpenRegister\Event\ObjectCreatingEvent;

/**
 * Contract of AssessmentResultAudience.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssessmentResultAudienceInterface {

	/**
	 * Stamp the audience of the AssessmentResult being created, merging with
	 * data other listeners already set.
	 *
	 * @param ObjectCreatingEvent $event The AssessmentResult creating event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-assessment-results-are-read-by-the-learner-their-manager-and-the-courses-teachers
	 */
	public function stamp(ObjectCreatingEvent $event): void;
}//end interface
