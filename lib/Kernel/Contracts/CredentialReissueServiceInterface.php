<?php

/**
 * Contract of CredentialReissueService
 *
 * The public surface of CredentialReissueService that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of CredentialReissueService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CredentialReissueServiceInterface {

	/**
	 * How many credentials of the course a reissue would touch and leave.
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return array{issued: int, revoked: int, expired: int}
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
	 */
	public function preview(string $courseId): array;

	/**
	 * Run a reissue over every issued credential of the course, in batches.
	 *
	 * @param string $courseId The course uuid.
	 * @param string $runId    The run id.
	 * @param string $reason   The reason given.
	 * @param IUser  $actor    The staff member who started the run.
	 *
	 * @return array{processed: int, skipped: int, failed: int}
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
	 */
	public function run(string $courseId, string $runId, string $reason, IUser $actor): array;

	/**
	 * The stored summary of a run, or null.
	 *
	 * @param string $runId The run id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
	 */
	public function summary(string $runId): ?array;
}//end interface
