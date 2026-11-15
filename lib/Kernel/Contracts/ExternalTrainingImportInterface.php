<?php

/**
 * Contract of ExternalTrainingImport
 *
 * The public surface of ExternalTrainingImport that other components call, so they depend
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

use DateTimeInterface;

/**
 * Contract of ExternalTrainingImport.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExternalTrainingImportInterface {

	/**
	 * The most rows one upload may carry.
	 */
	public const MAX_ROWS = 1000;

	/**
	 * Check every row and, unless this is a dry run, record the ready ones.
	 *
	 * Each row is reported, with `reason` and `reasonParams` when it is not ready, as `ready` (dry run) or `created`, `unmatched`
	 * (no learner of this tenant), `invalid` (a field is wrong), `duplicate`
	 * (an earlier row of the same file) or `skipped` (already recorded).
	 * All records of one upload share a `batchId`.
	 *
	 * @param array<int,mixed> $rows The parsed rows: learner, title, provider, kind,
	 *                               completedAt, validUntil, regulationSlug, evidenceNote.
	 * @param string $tenantId The caller's tenant; learners are matched in it only.
	 * @param string $submittedBy The caller's user id.
	 * @param array{dryRun: bool, now?: ?DateTimeInterface} $run `dryRun` true to report without writing; `now` the
	 *                                                           evaluation instant (injectable for tests).
	 *
	 * @return array{dryRun:bool,batchId:?string,summary:array<string,int>,rows:array<int,array<string,mixed>>} The report.
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-import-result-report
	 */
	public function import(
		array $rows,
		string $tenantId,
		string $submittedBy,
		array $run,
	): array;
}//end interface
