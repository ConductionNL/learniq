<?php

/**
 * Contract of BpvPlacementSteps
 *
 * The public surface of BpvPlacementSteps that other components call, so they depend
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
 * Contract of BpvPlacementSteps.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BpvPlacementStepsInterface {

	/**
	 * The steps of one placement, or none when it cannot be read.
	 *
	 * @param string $placementId The placement's uuid.
	 *
	 * @return array<int, array<string, string>>
	 *
	 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-placement-shows-where-it-stands
	 */
	public function forPlacement(string $placementId): array;
}//end interface
