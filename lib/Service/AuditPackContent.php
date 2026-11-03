<?php

/**
 * Learniq Audit Pack Content
 *
 * What an audit pack holds: the attributed entries, how many were left out,
 * and the chain verification over them; and the manifest that describes them.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The entries of an audit pack, the verification of their HMAC chain, and its manifest.
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */
final class AuditPackContent {
	/**
	 * Constructor.
	 *
	 * @param array<int,array<string,mixed>> $events       Serialised, filtered events.
	 * @param int                            $unattributed Entries left out because no tenant could be attributed.
	 * @param array<string,mixed>            $verification OR AuditHashService::verifyChain() response.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
	 */
	public function __construct(
		public readonly array $events,
		public readonly int $unattributed,
		public readonly array $verification,
	) {
	}//end __construct()

	/**
	 * The manifest.json content per ADR-008 §6.
	 *
	 * The signature status is `valid` only for a chain verified as valid. A
	 * broken chain cannot vouch for the key it was signed with either, so its
	 * key fingerprint reads `unavailable`.
	 *
	 * @param AuditPackScope $scope The tenant, regulation and period.
	 *
	 * @return string JSON string.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	public function manifestJson(AuditPackScope $scope): string {
		$verification    = $this->verification;
		$signatureStatus = 'broken';
		if (($verification['valid'] ?? false) === true) {
			$signatureStatus = 'valid';
		}

		$keyFingerprint = (string)($verification['keyFingerprint'] ?? 'unavailable');
		if (array_key_exists('brokenAt', $verification) === true) {
			$keyFingerprint = 'unavailable';
		}

		$manifest = [
			'schema_version' => '1.0',
			'tenant_id' => $scope->tenantId,
			'regulation_slug' => $scope->regulationSlug,
			'period' => ['from' => $scope->dateFrom, 'to' => $scope->dateTo],
			'event_count' => count($this->events),
			'signature_status' => $signatureStatus,
			'export_timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
			'key_fingerprint' => $keyFingerprint,
			// Entries in the period whose object is gone or has no tenant: left
			// out, because they cannot be shown to be this tenant's.
			'unattributed_entries_excluded' => $this->unattributed,
			'generator' => 'learniq/AuditPackExportController@0.1.0',
		];

		return (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}//end manifestJson()
}//end class
