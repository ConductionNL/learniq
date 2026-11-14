<?php

/**
 * Contract of LearningRecordImportIntakeService
 *
 * The public surface of LearningRecordImportIntakeService that other components call, so they depend
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
 * Contract of LearningRecordImportIntakeService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LearningRecordImportIntakeServiceInterface {

	/**
	 * Create the `LearningRecordImport` object for a stored upload and drive
	 * its `parse` transition.
	 *
	 * A transition that raises is logged and swallowed: the import row itself
	 * is already persisted, so the caller still gets the (then `uploaded`)
	 * record back rather than a lost upload.
	 *
	 * @param string $applicationId UUID of the Application this import is evidence for.
	 * @param string $sourceFilename Original filename of the uploaded bundle.
	 * @param string $sourceFormat `scholiq-learning-record` | `elm-europass`.
	 * @param string $uploadedBy Nextcloud user id of the uploader.
	 * @param string $sourceRef nc:files path the bytes were stored at.
	 * @param string $tenantId Tenant UUID to stamp on the created object.
	 *
	 * @return array<string,mixed>|null The created (now `parsed`, or `uploaded`+errorMessage) record, or
	 *                                  null when it could not be created.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-coordinator-uploads-a-prior-scholiq-export-during-intake-and-sees-a-verified-coverage-report
	 */
	public function createImport(
		string $applicationId,
		string $sourceFilename,
		string $sourceFormat,
		string $uploadedBy,
		string $sourceRef,
		string $tenantId,
	): ?array;

	/**
	 * Resolve the requesting tenant's ID.
	 *
	 * The authenticated user's own tenant binding wins; an unbound user belongs
	 * to the default tenant (CallerTenantResolver).
	 *
	 * @param IUser $user Authenticated user whose tenant binding is read.
	 *
	 * @return string Tenant UUID, or the default tenant when unbound.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
	 */
	public function resolveTenantId(IUser $user): string;

	/**
	 * Write the raw uploaded bytes into the caller's nc:files home, mirroring
	 * `CoursePackageImportService::writeBytesToFiles()`'s destination
	 * convention (`Learniq/{tenant}/...`).
	 *
	 * @param string $tmpPath Absolute path to the uploaded tmp file.
	 * @param string $ownerUid Nextcloud user id who will own the file.
	 * @param string $tenantId Tenant UUID, used to namespace the destination folder.
	 *
	 * @return string|null The nc:files path (relative, no leading slash), or null on failure.
	 *
	 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md#task-4-2
	 */
	public function storeUpload(string $tmpPath, string $ownerUid, string $tenantId): ?string;
}//end interface
