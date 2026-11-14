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

use OCA\Learniq\Kernel\Contracts\AuditPackBuilderInterface;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\IUser;

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
class AuditPackBuilder implements AuditPackBuilderInterface {
	/**
	 * Constructor.
	 *
	 * @param AuditPeriodReader $period Reads the audit entries of the export period.
	 * @param AuditHashService $auditHashService OR HMAC chain verification service.
	 * @param CallerTenantResolver $tenants Resolves the tenant: the per-user binding, else the default tenant.
	 * @param CsvCellSanitizer $sanitizer CSV formula-injection neutraliser.
	 * @param VerwerkingsregisterCsvBuilder $registerCsv AVG Art. 30 register artefact builder.
	 * @param ExternalTrainingCsvBuilder $trainingCsv External-training evidence artefact builder.
	 * @param AuditEntryAttribution $attribution Keeps the entries whose object is in the caller's tenant.
	 */
	public function __construct(
		private readonly AuditPeriodReader $period,
		private readonly AuditHashService $auditHashService,
		private readonly CallerTenantResolver $tenants,
		private readonly CsvCellSanitizer $sanitizer,
		private readonly VerwerkingsregisterCsvBuilder $registerCsv,
		private readonly ExternalTrainingCsvBuilder $trainingCsv,
		private readonly AuditEntryAttribution $attribution,
	) {

	}//end __construct()

	/**
	 * Build the audit-pack ZIP bytes for one request.
	 *
	 * #184: only entries about objects in the requesting user's own tenant are
	 * exported (AuditEntryAttribution); entries from other tenants never are.
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

		// The trail has no tenant column, and AuditTrailMapper::findAll() drops
		// every filter outside its column allowlist, so a tenant_id filter here
		// returned every tenant's entries. The tenant is decided by the object
		// each entry is about; the period by AuditPeriodReader.
		$own = $this->attribution->ownEntries(
			entries: $this->period->entries(dateFrom: $dateFrom, dateTo: $dateTo),
			tenantId: $tenantId,
			regulationSlug: $regulationSlug
		);
		$events = $own['events'];

		// #192: scope verifyChain to the ID range of the matched events so the
		// integrity report covers exactly the entries that appear in the export,
		// not the whole audit log. Fixes #192.
		$bounds = $this->resolveExportedIdBounds(events: $events);
		$verification = $this->verifyChainForRange(minId: $bounds['minId'], maxId: $bounds['maxId']);

		return $this->buildZip(
			files: $this->buildPackFiles(
				scope: new AuditPackScope(tenantId: $tenantId, regulationSlug: $regulationSlug, dateFrom: $dateFrom, dateTo: $dateTo),
				content: new AuditPackContent(events: $events, unattributed: $own['unattributed'], verification: $verification)
			)
		);

	}//end build()

	/**
	 * Resolve the requesting tenant's ID.
	 *
	 * #184: the authenticated user's own tenant binding is preferred; an
	 * unbound user belongs to the default tenant (CallerTenantResolver).
	 *
	 * @param IUser $user Authenticated user whose tenant binding is read.
	 *
	 * @return string Tenant UUID, or the default tenant when unbound.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function resolveTenantId(IUser $user): string {
		return $this->tenants->resolve(user: $user);
	}//end resolveTenantId()

	/**
	 * Whether both bounds of the export period are dates.
	 *
	 * @param string $dateFrom ISO-8601 lower bound.
	 * @param string $dateTo ISO-8601 upper bound.
	 *
	 * @return bool True when both parse.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
	 */
	public function isPeriod(string $dateFrom, string $dateTo): bool {
		return $this->period->parseBound(value: $dateFrom, endOfDay: false) !== null
			&& $this->period->parseBound(value: $dateTo, endOfDay: true) !== null;
	}//end isPeriod()

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
	 * @param AuditPackScope   $scope   The tenant, regulation and period.
	 * @param AuditPackContent $content The entries and their chain verification.
	 *
	 * @return array<string,string> Archive filename => file content.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function buildPackFiles(AuditPackScope $scope, AuditPackContent $content): array {
		return [
			'audit-trail.ndjson' => $this->buildNdjson(events: $content->events),
			'audit-trail.csv' => $this->buildCsv(events: $content->events),
			'manifest.json' => $content->manifestJson(scope: $scope),
			'signature-verification.txt' => $this->buildVerificationTxt(verification: $content->verification),
			'verwerkingsregister.csv' => $this->registerCsv->build(
				dateFrom: $scope->dateFrom,
				dateTo: $scope->dateTo,
			),
			'external-training.csv' => $this->trainingCsv->build(
				regulationSlug: $scope->regulationSlug,
				dateFrom: $scope->dateFrom,
				dateTo: $scope->dateTo,
				tenantId: $scope->tenantId,
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
			// AuditTrail::jsonSerialize() returns register, schema and object
			// as integers; the sanitizer takes strings (strict types).
			fputcsv(
				$handle,
				[
					$this->sanitizer->sanitize(value: (string)($event['uuid'] ?? '')),
					$this->sanitizer->sanitize(value: (string)($event['action'] ?? '')),
					$this->sanitizer->sanitize(value: (string)($event['objectUuid'] ?? ($event['object'] ?? ''))),
					$this->sanitizer->sanitize(value: (string)($event['register'] ?? '')),
					$this->sanitizer->sanitize(value: (string)($event['schema'] ?? '')),
					$this->sanitizer->sanitize(value: (string)($event['user'] ?? '')),
					$this->sanitizer->sanitize(value: (string)($event['created'] ?? '')),
				]
			);
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end buildCsv()

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
		return (new InMemoryZip(tempPrefix: 'learniq_audit_'))->build(files: $files);
	}//end buildZip()
}//end class
