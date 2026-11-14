<?php

/**
 * Contract of ComplianceRollupService
 *
 * The public surface of ComplianceRollupService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of ComplianceRollupService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplianceRollupServiceInterface {

	/**
	 * The roll-up, one row per department level, sorted by path.
	 *
	 * @param DateTimeImmutable|null $now Evaluation instant (injectable for tests).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	public function byDepartment(?DateTimeImmutable $now=null): array;
}//end interface
