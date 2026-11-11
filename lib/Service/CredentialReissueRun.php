<?php

/**
 * Learniq credential reissue run.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * One reissue run over a course's credentials: its id and the decision
 * behind it. Built by CredentialReissueService::run() and handed to the
 * per-credential steps.
 *
 * @spec openspec/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
 */
final class CredentialReissueRun {

	/**
	 * Constructor.
	 *
	 * @param string          $runId    The run id.
	 * @param ReissueDecision $decision Who started the run, and why.
	 */
	public function __construct(
		public readonly string $runId,
		public readonly ReissueDecision $decision,
	) {
	}//end __construct()

	/**
	 * The history fields a reissue in this run writes.
	 *
	 * @param int    $previousCount How often the credential was reissued before.
	 * @param string $now           The reissue instant.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
	 */
	public function historyFields(int $previousCount, string $now): array {
		return [
			'reissuedAt' => $now,
			'reissueCount' => ($previousCount + 1),
			'reissueReason' => $this->decision->reason,
			'reissuedBy' => $this->decision->actor->getUID(),
			'reissueRunId' => $this->runId,
		];
	}//end historyFields()
}//end class
