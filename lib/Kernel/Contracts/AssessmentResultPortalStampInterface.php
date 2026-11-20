<?php

/**
 * Contract of AssessmentResultPortalStamp
 *
 * The public surface of AssessmentResultPortalStamp that other components call, so they depend
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
 * Contract of AssessmentResultPortalStamp.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssessmentResultPortalStampInterface {

	/**
	 * Stamp the attempt being created, merging with what other steps set.
	 *
	 * @param ObjectCreatingEvent $event The AssessmentResult creating event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-every-attempt-carries-a-server-stamped-learnerref-and-assessment-title
	 */
	public function stamp(ObjectCreatingEvent $event): void;
}//end interface
