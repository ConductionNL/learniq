<?php

/**
 * Contract of ExternalTrainingService
 *
 * The public surface of ExternalTrainingService that other components call, so they depend
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
 * Contract of ExternalTrainingService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExternalTrainingServiceInterface {

	/**
	 * Build the manual Credential payload to issue on verification.
	 *
	 * Returns the object array to persist (via OR `source: manual`) so the
	 * certification capability's existing expiry alerts and renewal auto-enrol
	 * cover external certificates too. The caller persists it and writes the new
	 * credential's UUID back onto the record as `credentialId`. No Credential
	 * schema change is introduced.
	 *
	 * @param array<string,mixed> $record The verified external-training record.
	 * @param string $issuedBy NC user ID / issuer name on the credential.
	 *
	 * @return array<string,mixed> The Credential object payload to save.
	 *
	 * @spec openspec/changes/archive/2026-06-15-external-training-recording/tasks.md
	 */
	public function buildManualCredentialPayload(array $record, string $issuedBy): array;

	/**
	 * Create one external-training record for each of N learners (bulk entry).
	 *
	 * All records share a single `batchId` so the classroom session can be
	 * verified and audited together. Each record starts in `submitted`. The
	 * shared evidence attachment is uploaded once and linked per record by the
	 * caller (OR file-attachment API); this method only creates the records.
	 *
	 * @param array<string> $learnerIds The learners attending the session.
	 * @param array<string,mixed> $shared Shared fields: title, provider,
	 *                                    kind, completedAt,
	 *                                    regulationSlug?, validUntil?,
	 *                                    evidenceNote?, submittedBy,
	 *                                    tenant_id.
	 *
	 * @return string The generated batchId (empty when nothing was created).
	 *
	 * @spec openspec/changes/archive/2026-06-15-external-training-recording/tasks.md
	 */
	public function bulkRecord(array $learnerIds, array $shared): string;

	/**
	 * The evidence class that currently covers a learner for a regulation.
	 *
	 * Returns the strongest matching class for display in the coverage view, or
	 * `null` when the learner is not covered. Precedence: attestation, then
	 * credential, then external-training.
	 *
	 * @param string $learnerId LearnerProfile UUID.
	 * @param string $regulationSlug Regulation slug.
	 * @param DateTimeInterface|null $now Evaluation instant.
	 *
	 * @return string|null One of 'attestation'|'credential'|'external-training', or null.
	 *
	 * @spec openspec/changes/archive/2026-06-15-external-training-recording/tasks.md
	 */
	public function coveringEvidenceClass(
		string $learnerId,
		string $regulationSlug,
		?DateTimeInterface $now = null,
	): ?string;

	/**
	 * Whether a learner is covered for a regulation by ANY evidence class.
	 *
	 * Coverage holds when the learner has at least one of:
	 *   1. a signed Attestation for the regulation, OR
	 *   2. a valid (non-expired, non-revoked) Credential for the regulation, OR
	 *   3. a verified ExternalTrainingRecord for the regulation whose
	 *      `validUntil` (if set) has not passed.
	 *
	 * The denominator (population) is unchanged by this method; it only widens
	 * the numerator predicate to include verified external records.
	 *
	 * @param string $learnerId LearnerProfile UUID.
	 * @param string $regulationSlug Regulation slug to test.
	 * @param DateTimeInterface|null $now Evaluation instant
	 *                                    (injectable for tests).
	 *
	 * @return bool True when the learner counts as covered.
	 *
	 * @spec openspec/changes/archive/2026-06-15-external-training-recording/tasks.md
	 */
	public function isLearnerCovered(
		string $learnerId,
		string $regulationSlug,
		?DateTimeInterface $now = null,
	): bool;
}//end interface
