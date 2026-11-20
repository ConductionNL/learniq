<?php

/**
 * Contract of CertificateIssueService
 *
 * The public surface of CertificateIssueService that other components call, so they depend
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
 * Contract of CertificateIssueService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CertificateIssueServiceInterface {

	/**
	 * Issue the certificates of a course day in rounding off to every
	 * participant with a passing result, naming who was skipped.
	 *
	 * @param string $cohortId The course day.
	 * @param string $userId   Who issues them.
	 *
	 * @return array<string, mixed> `issued`, `skipped`, `alreadyIssued` and `summary`.
	 *
	 * @throws \Throwable When the day is not in rounding off, or OpenRegister cannot be read or written.
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-thursdays-f-gassen-day
	 */
	public function issue(string $cohortId, string $userId): array;
}//end interface
