<?php

/**
 * Contract of CredentialMigrationService
 *
 * The public surface of CredentialMigrationService that other components call, so they depend
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
 * Contract of CredentialMigrationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CredentialMigrationServiceInterface {

	/**
	 * Check every row, and on commit write the ones that are ready.
	 *
	 * @param array<int, mixed>      $rows       The rows.
	 * @param string                 $tenantId   The organisation migrating.
	 * @param string                 $migratedBy The user committing.
	 * @param bool                   $commit     False for the preview: nothing is written.
	 * @param DateTimeImmutable|null $now        The moment of the run.
	 *
	 * @return array{commit: bool, runId: string|null, summary: array<string, int>, rows: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
	 */
	public function migrate(array $rows, string $tenantId, string $migratedBy, bool $commit, ?DateTimeImmutable $now = null): array;
}//end interface
