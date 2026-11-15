<?php

/**
 * Learniq Credential Migration
 *
 * Brings certificates over from the system learniq replaces, with their old
 * number, old issuer and validity dates. Every row passes one check, in a dry
 * run for the preview and again on commit, so the preview and the result
 * cannot disagree (credentials-migrate-with-validity design D1). A migrated
 * certificate is not signed: learniq did not issue it (D2). A second run only
 * adds what is new, keyed on learner, course and old number (D3).
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Checks and writes migrated certificates.
 *
 * A row: `learner` (learner id, personal number or e-mail), `course` (course
 * code) or `regulation` (regulation slug), `legacyNumber`, `legacyIssuer`,
 * `issuedAt` and an optional `expiresAt` (both `YYYY-MM-DD`), and an optional
 * `kind` (default `certificate`).
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */
class CredentialMigrationService {

	/**
	 * The run that checks and writes the rows.
	 *
	 * @var CredentialMigrationRun
	 */
	private readonly CredentialMigrationRun $run;

	/**
	 * Constructor.
	 *
	 * @param ObjectService                $objectService Reads courses, regulations and credentials; writes credentials.
	 * @param ExternalTrainingLearnerMatch $learnerMatch  Matches a row's learner, as the external-training upload does.
	 * @param LoggerInterface              $logger        Logs a failed write.
	 */
	public function __construct(
		ObjectService $objectService,
		ExternalTrainingLearnerMatch $learnerMatch,
		LoggerInterface $logger,
	) {
		$this->run = new CredentialMigrationRun(objectService: $objectService, learnerMatch: $learnerMatch, logger: $logger);
	}//end __construct()

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
	public function migrate(array $rows, string $tenantId, string $migratedBy, bool $commit, ?DateTimeImmutable $now = null): array {
		$at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		return $this->run->migrate(rows: $rows, run: ['tenantId' => $tenantId, 'by' => $migratedBy, 'commit' => $commit, 'at' => $at]);
	}//end migrate()
}//end class
