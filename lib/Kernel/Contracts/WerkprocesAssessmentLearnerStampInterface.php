<?php

/**
 * Contract of WerkprocesAssessmentLearnerStamp
 *
 * The public surface of WerkprocesAssessmentLearnerStamp that other components call, so they depend
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
 * Contract of WerkprocesAssessmentLearnerStamp.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WerkprocesAssessmentLearnerStampInterface {

	/**
	 * The Nextcloud user id of the learner on a placement, or null when the
	 * placement does not exist or names nobody. Reads without RBAC: the
	 * assessor (a praktijkopleider) may write an assessment without reading
	 * the placement, and only the user id leaves this method. Errors from
	 * OpenRegister propagate, so a caller can tell "nobody" from "could not
	 * look".
	 *
	 * @param string $placementId The BpvPlacement uuid.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
	 */
	public function learnerOfPlacement(string $placementId): ?string;
}//end interface
