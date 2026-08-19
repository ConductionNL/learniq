<?php

/**
 * Learniq Audit Pack Builder
 *
 * Assembles the ADR-008 §6 compliance audit-pack ZIP: queries OR's audit trail,
 * verifies the HMAC chain over exactly the exported ID range, renders every
 * artefact, and packs them into an in-memory archive.
 *
 * This is a legitimate PHP file per ADR-031 §"Document/ZIP generation":
 * building a ZIP containing ndjson/csv/manifest/signature-verification cannot be
 * expressed declaratively. All heavy lifting (audit-trail query, HMAC chain
 * verification) is delegated to OR's AuditTrailMapper and AuditHashService.
 *
 * Per ADR-022: uses OR's audit-trail-query abstraction; does NOT maintain a
 * local event store or write any audit entries itself.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\IConfig;
use OCP\IUser;
use ZipArchive;

/**
 * Builds the compliance audit-pack ZIP for a tenant, regulation and period.
 *
 * The archive contains:
 *   - audit-trail.ndjson  (one JSON object per line, all matching events)
 *   - audit-trail.csv     (flat CSV of the same events)
 *   - manifest.json       (tenant_id, period, regulation_slug, event_count,
 *                          signature_status, export_timestamp, key_fingerprint)
 *   - signature-verification.txt  (HMAC chain verification report)
 *   - verwerkingsregister.csv     (OR-PA-7 Art. 30 register slice; loud warning if absent)
 *   - external-training.csv       (verified out-of-LMS training evidence)
 *
 * @psalm-api
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class AuditPackBuilder {
	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper OR audit-trail database mapper.
	 * @param AuditHashService $auditHashService OR HMAC chain verification service.
	 * @param IConfig $config NC config for tenant ID lookup.
	 * @param CsvCellSanitizer $sanitizer CSV formula-injection neutraliser.
	 * @param VerwerkingsregisterCsvBuilder $registerCsv AVG Art. 30 register artefact builder.
	 * @param ExternalTrainingCsvBuilder $trainingCsv External-training evidence artefact builder.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly AuditHashService $auditHashService,
		private readonly IConfig $config,
		private readonly CsvCellSanitizer $sanitizer,
		private readonly VerwerkingsregisterCsvBuilder $registerCsv,
		private readonly ExternalTrainingCsvBuilder $trainingCsv,
	) {

	}//end __construct()

	/**
	 * Build the audit-pack ZIP bytes for one request.
	 *
	 * #184: the audit-trail query is always scoped to the requesting user's own
	 * tenant so entries from other tenants are never returned.
	 *
	 * @param IUser $user Authenticated user whose tenant scopes the pack.
	 * @param string $regulationSlug Regulation slug to filter (e.g. 'NIS2').
	 * @param string $dateFrom ISO-8601 date lower bound (inclusive).
	 * @param string $dateTo ISO-8601 date upper bound (inclusive).
	 *
	 * @return string Raw ZIP bytes.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	public function build(IUser $user, string $regulationSlug, string $dateFrom, string $dateTo): string {
		$tenantId = $this->resolveTenantId(user: $user);

		// Query OR's audit trail via the real mapper — filters available columns.
		$entries = $this->auditTrailMapper->findAll(
			filters: [
				'created' => $dateFrom . ',' . $dateTo,
				'tenant_id' => $tenantId,
			],
			sort: ['created' => 'ASC']
		);

		$events = $this->collectMatchingEvents(entries: $entries, regulationSlug: $regulationSlug);

		// #192: scope verifyChain to the ID range of the matched events so the
		// integrity report covers exactly the entries that appear in the export,
		// not the whole audit log. Fixes #192.
		$bounds = $this->resolveExportedIdBounds(events: $events);
		$verification = $this->verifyChainForRange(minId: $bounds['minId'], maxId: $bounds['maxId']);

		return $this->buildZip(
			files: $this->buildPackFiles(
				events: $events,
				verification: $verification,
				tenantId: $tenantId,
				regulationSlug: $regulationSlug,
				dateFrom: $dateFrom,
				dateTo: $dateTo,
			)
		);

	}//end build()

	/**
	 * Resolve the requesting tenant's ID.
	 *
	 * #184: `instanceid` is the same for every tenant on the instance, so the
	 * authenticated user's own tenant binding (written by the admin module) is
	 * preferred; `instanceid` is only the fallback when no per-user mapping exists.
	 *
	 * @param IUser $user Authenticated user whose tenant binding is read.
	 *
	 * @return string Tenant UUID, or the instance id when unbound.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function resolveTenantId(IUser $user): string {
		$userTenantId = $this->config->getUserValue(
			userId: $user->getUID(),
			appName: 'learniq',
			key: 'tenant_id',
			default: ''
		);

		if ($userTenantId !== '') {
			return $userTenantId;
		}

		return (string)$this->config->getSystemValue('instanceid', 'unknown');
	}//end resolveTenantId()

	/**
	 * Serialise the audit-trail entities and keep only the entries whose
	 * `changed` payload carries the requested regulation slug.
	 *
	 * @param array<int,mixed> $entries AuditTrail entities from the OR mapper.
	 * @param string $regulationSlug Regulation slug to filter on ('' keeps everything).
	 *
	 * @return array<int,array<string,mixed>> Serialised, filtered events in mapper order.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function collectMatchingEvents(array $entries, string $regulationSlug): array {
		$events = [];
		foreach ($entries as $entry) {
			$row = (array)$entry->jsonSerialize();

			// The regulation slug lives inside the `changed` JSON field.
			$changed = [];
			if (is_string($row['changed'] ?? null) === true) {
				$changed = (array)json_decode($row['changed'], associative: true);
			}

			if ($regulationSlug !== '' && ($changed['regulationSlug'] ?? '') !== $regulationSlug) {
				continue;
			}

			$events[] = $row;
		}

		return $events;
	}//end collectMatchingEvents()

	/**
	 * Find the lowest and highest audit-entry ID among the exported events.
	 *
	 * #192: these bounds scope the HMAC chain verification to the same entries
	 * the pack contains. Entries without a usable id are ignored rather than
	 * collapsing the range to zero.
	 *
	 * @param array<int,array<string,mixed>> $events Serialised, filtered events.
	 *
	 * @return array{minId: int|null, maxId: int|null} Bounds, both null when no event carried an id.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md
	 */
	private function resolveExportedIdBounds(array $events): array {
		$minId = null;
		$maxId = null;

		foreach ($events as $event) {
			$id = (int)($event['id'] ?? 0);
			if ($id === 0) {
				continue;
			}

			if ($minId === null || $id < $minId) {
				$minId = $id;
			}

			if ($maxId === null || $id > $maxId) {
				$maxId = $id;
			}
		}

		return [
			'minId' => $minId,
			'maxId' => $maxId,
		];

	}//end resolveExportedIdBounds()

	/**
	 * Verify the audit HMAC chain, scoped to the exported ID range when one exists.
	 *
	 * #192: the integrity report must cover the same entries as the export, so the
	 * date-scoped ID bounds are forwarded when both are known. When either bound is
	 * absent there is no range to scope to and the whole chain is verified.
	 *
	 * The argument names MUST stay `from`/`to`: they bind by name against
	 * OpenRegister's real `AuditHashService::verifyChain(?int $from, ?int $to)`.
	 *
	 * @param int|null $minId Lowest exported audit-entry ID, or null when unknown.
	 * @param int|null $maxId Highest exported audit-entry ID, or null when unknown.
	 *
	 * @return array<string,mixed> OR AuditHashService::verifyChain() response.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md
	 */
	private function verifyChainForRange(?int $minId, ?int $maxId): array {
		if ($minId === null || $maxId === null) {
			return $this->auditHashService->verifyChain();
		}

		return $this->auditHashService->verifyChain(from: $minId, to: $maxId);
	}//end verifyChainForRange()

	/**
	 * Build every artefact that goes into the audit-pack ZIP, keyed by its
	 * in-archive filename.
	 *
	 * The verwerkingsregister and external-training artefacts are always
	 * present: when the platform capability behind one is missing it degrades
	 * to loud warning content rather than being silently omitted.
	 *
	 * @param array<int,array<string,mixed>> $events Serialised, filtered events.
	 * @param array<string,mixed> $verification OR AuditHashService::verifyChain() response.
	 * @param string $tenantId Resolved tenant scope.
	 * @param string $regulationSlug Regulation slug for this pack.
	 * @param string $dateFrom ISO-8601 period start.
	 * @param string $dateTo ISO-8601 period end.
	 *
	 * @return array<string,string> Archive filename => file content.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function buildPackFiles(
		array $events,
		array $verification,
		string $tenantId,
		string $regulationSlug,
		string $dateFrom,
		string $dateTo,
	): array {
		$signatureStatus = 'broken';
		if (($verification['valid'] ?? false) === true) {
			$signatureStatus = 'valid';
		}

		// A broken chain cannot vouch for the key it was signed with either.
		$keyFingerprint = (string)($verification['keyFingerprint'] ?? 'unavailable');
		if (array_key_exists('brokenAt', $verification) === true) {
			$keyFingerprint = 'unavailable';
		}

		$exportTimestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

		return [
			'audit-trail.ndjson' => $this->buildNdjson(events: $events),
			'audit-trail.csv' => $this->buildCsv(events: $events),
			'manifest.json' => $this->buildManifestJson(
				tenantId: $tenantId,
				regulationSlug: $regulationSlug,
				dateFrom: $dateFrom,
				dateTo: $dateTo,
				eventCount: count($events),
				signatureStatus: $signatureStatus,
				exportTimestamp: $exportTimestamp,
				keyFingerprint: $keyFingerprint,
			),
			'signature-verification.txt' => $this->buildVerificationTxt(verification: $verification),
			'verwerkingsregister.csv' => $this->registerCsv->build(
				dateFrom: $dateFrom,
				dateTo: $dateTo,
			),
			'external-training.csv' => $this->trainingCsv->build(
				regulationSlug: $regulationSlug,
				dateFrom: $dateFrom,
				dateTo: $dateTo,
				tenantId: $tenantId,
			),
		];

	}//end buildPackFiles()

	/**
	 * Render events as newline-delimited JSON (one object per line).
	 *
	 * @param array<int,array<string,mixed>> $events Audit-trail events.
	 *
	 * @return string NDJSON string.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function buildNdjson(array $events): string {
		$lines = [];
		foreach ($events as $event) {
			$lines[] = (string)json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		return implode("\n", $lines) . "\n";
	}//end buildNdjson()

	/**
	 * Render events as a flat CSV (header row + one data row per event).
	 *
	 * @param array<int,array<string,mixed>> $events Audit-trail events.
	 *
	 * @return string CSV string.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function buildCsv(array $events): string {
		if (empty($events) === true) {
			return "event_id,action,object,register,schema,user,created\n";
		}

		$handle = fopen('php://memory', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, ['event_id', 'action', 'object', 'register', 'schema', 'user', 'created']);

		foreach ($events as $event) {
			fputcsv(
				$handle,
				[
					$this->sanitizer->sanitize(value: $event['uuid'] ?? ''),
					$this->sanitizer->sanitize(value: $event['action'] ?? ''),
					$this->sanitizer->sanitize(value: $event['object'] ?? ''),
					$this->sanitizer->sanitize(value: $event['register'] ?? ''),
					$this->sanitizer->sanitize(value: $event['schema'] ?? ''),
					$this->sanitizer->sanitize(value: $event['user'] ?? ''),
					$this->sanitizer->sanitize(value: $event['created'] ?? ''),
				]
			);
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end buildCsv()

	/**
	 * Build the manifest.json content per ADR-008 §6.
	 *
	 * @param string $tenantId Tenant UUID or instanceid.
	 * @param string $regulationSlug Regulation slug.
	 * @param string $dateFrom Period start.
	 * @param string $dateTo Period end.
	 * @param int $eventCount Total events in pack.
	 * @param string $signatureStatus OR chain verification result.
	 * @param string $exportTimestamp ISO-8601 timestamp of this export.
	 * @param string $keyFingerprint Public verification key fingerprint.
	 *
	 * @return string JSON string.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function buildManifestJson(
		string $tenantId,
		string $regulationSlug,
		string $dateFrom,
		string $dateTo,
		int $eventCount,
		string $signatureStatus,
		string $exportTimestamp,
		string $keyFingerprint,
	): string {
		$manifest = [
			'schema_version' => '1.0',
			'tenant_id' => $tenantId,
			'regulation_slug' => $regulationSlug,
			'period' => ['from' => $dateFrom, 'to' => $dateTo],
			'event_count' => $eventCount,
			'signature_status' => $signatureStatus,
			'export_timestamp' => $exportTimestamp,
			'key_fingerprint' => $keyFingerprint,
			'generator' => 'learniq/AuditPackExportController@0.1.0',
		];

		return (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}//end buildManifestJson()

	/**
	 * Build the signature-verification.txt content from OR's chain verification result.
	 *
	 * @param array<string,mixed> $verification OR AuditHashService::verifyChain() response.
	 *
	 * @return string Plain-text verification report.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
	 */
	private function buildVerificationTxt(array $verification): string {
		$valid = ($verification['valid'] ?? false) === true;
		$status = 'broken';
		if ($valid === true) {
			$status = 'valid';
		}

		$entriesVerified = $verification['entriesVerified'] ?? 0;
		$brokenAt = $verification['brokenAt'] ?? null;

		$lines = [];
		$lines[] = '=== Learniq Compliance Audit Pack — Signature Verification Report ===';
		$lines[] = '';
		$lines[] = 'Status          : ' . $status;
		$lines[] = 'Entries verified: ' . $entriesVerified;

		$lines[] = '';
		if ($brokenAt !== null) {
			$lines[] = 'WARNING: Chain integrity broken at entry id: ' . $brokenAt;
			$lines[] = 'This indicates a record was modified or deleted after recording.';
		}

		if ($brokenAt === null) {
			$lines[] = 'All HMAC signatures verified. Evidence log is intact.';
		}

		$lines[] = '';
		$lines[] = 'This report is generated by OpenRegister\'s AuditHashService::verifyChain().';
		$lines[] = 'For offline verification cross-reference the NDJSON file with the chain hashes.';

		return implode("\n", $lines) . "\n";
	}//end buildVerificationTxt()

	/**
	 * Build an in-memory ZIP archive from named string content entries.
	 *
	 * @param array<string,string> $files Map of filename => content.
	 *
	 * @return string Raw ZIP bytes.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function buildZip(array $files): string {
		$tmpFile = tempnam(sys_get_temp_dir(), 'learniq_audit_');
		if ($tmpFile === false) {
			return '';
		}

		$zip = new ZipArchive();
		if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
			unlink($tmpFile);
			return '';
		}

		foreach ($files as $filename => $content) {
			$zip->addFromString($filename, $content);
		}

		$zip->close();

		$zipContent = (string)file_get_contents($tmpFile);
		unlink($tmpFile);

		return $zipContent;
	}//end buildZip()
}//end class
