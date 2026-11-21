<?php

/**
 * Learniq Learning Record Export Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OR lifecycle guard for `LearningRecordExport`'s `generate` transition.
 * Reads `LearningRecordAggregationService::compose()`'s live composition,
 * splits it into an ELM/Europass-shaped `elm` section (per in-scope
 * Credential: `edciPayload` when non-empty else `openbadges3Payload`,
 * verbatim — the identical fallback `WalletOfferDelegationService
 * ::buildOfferRequest()` already uses) and a lossless `scholiqNative`
 * section, populates `coverageReport[]` (mirroring
 * `CoursePackageImportReport.entries` verbatim, applied to output instead
 * of input), stores the bundle as an nc:files attachment (`bundleRef`), and
 * delegates signing to `LearningRecordExportSigningService`.
 *
 * Fails closed: the guard refuses `generate` when there is no learner,
 * tenant or signing key, and generate() throws on any later failure, which
 * aborts the transition — never leaves partial bundle state.
 *
 * Legitimate PHP per ADR-031 "Lifecycle guard" — referenced from
 * `LearningRecordExport`'s `x-openregister-lifecycle.transitions.generate
 * .requires` in learniq_register.json. The bundle is written by the
 * transition's LearningRecordExportGenerateAction (learniq#983).
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Learniq\Kernel\Contracts\LearningRecordExportServiceInterface;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Guards `LearningRecordExport`'s `generate` transition and assembles the
 * signed dual-shaped bundle.
 *
 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md#task-2-3
 */
class LearningRecordExportService implements LifecycleGuardInterface, LearningRecordExportServiceInterface {

	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Refusal when the export names no learner or tenant.
	 */
	private const MISSING_SUBJECT = 'Missing learnerRef or tenant_id — cannot compose a record to export.';

	/**
	 * Refusal when the tenant has no signing key.
	 */
	private const MISSING_KEY = 'No signing key configured for this tenant — an admin must generate one before an export can be signed.';

	/**
	 * Schemas whose per-item timestamp is unambiguous enough to apply
	 * periodFrom/periodTo narrowing against — mapped to the field name
	 * holding that timestamp. Every other composed collection (Enrolment,
	 * FinalGrade, CompetencyAttainment, Portfolio/PortfolioEntry,
	 * BpvPlacement, the LessonCompletion per-course summary) is a
	 * container/roll-up with no single comparable "when this evidence
	 * happened" instant, so period narrowing is deliberately NOT applied to
	 * those — they are always `included`, never silently dropped for lack
	 * of a date to compare.
	 */
	private const PERIOD_FIELD_BY_SCHEMA = [
		'credential' => 'issuedAt',
		'external-training-record' => 'completedAt',
		'werkproces-assessment' => 'assessedAt',
		'report-card' => 'composedAt',
	];

	/**
	 * Staff professional-judgment schemas explicitly OUT of
	 * LearningRecordAggregationService's scope. Reported in coverageReport[]
	 * as `omitted` (never included) whenever a row exists for the learner
	 * within the requested period, so the exclusion boundary is visible, not
	 * silent — design.md "What the learner controls vs. what stays
	 * institutional".
	 */
	private const EXCLUDED_SCHEMA_DATE_FIELD = [
		'dossier-note' => 'date',
		'behaviour-incident' => 'occurredAt',
		'wellbeing-check-in' => 'submittedAt',
	];

	/**
	 * Constructor.
	 *
	 * @param LearningRecordAggregationService $aggregationService Cross-schema composition.
	 * @param LearningRecordExportSigningService $signingService Bundle canonicalisation + signing.
	 * @param ObjectService $objectService OR object read service (excluded-schema lookups).
	 * @param LearningRecordBundleWriter $bundleWriter nc:files writer for the signed bundle.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearningRecordAggregationService $aggregationService,
		private readonly LearningRecordExportSigningService $signingService,
		private readonly ObjectService $objectService,
		private readonly LearningRecordBundleWriter $bundleWriter,
	) {
	}//end __construct()

	/**
	 * OpenRegister lifecycle guard entry-point for `LearningRecordExport`'s
	 * `generate` transition.
	 *
	 * Refuses the transition when the export can not be composed or signed:
	 * a missing learnerRef or tenant, or no signing key for the tenant. The
	 * bundle itself is assembled by generate(), run by the transition's
	 * {@see \OCA\Learniq\Lifecycle\Action\LearningRecordExportGenerateAction},
	 * because OpenRegister hands a guard the object by value (learniq#983).
	 *
	 * @param array<string,mixed> $object The LearningRecordExport as it would be saved.
	 * @param string $action The transition, `generate`.
	 * @param string $userId The caller, or '' without a session (unused: this guard does not depend on who asks).
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-generation-fails-closed-and-blocks-the-transition-on-error
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$tenantId = (string)($object['tenant_id'] ?? '');
		if ((string)($object['learnerRef'] ?? '') === '' || $tenantId === '') {
			return GuardResult::deny(self::MISSING_SUBJECT);
		}

		if ($this->signingService->resolveIssuerDid(tenantId: $tenantId) === null) {
			return GuardResult::deny(self::MISSING_KEY);
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Assemble, sign and store the export bundle.
	 *
	 * @param array<string,mixed> $export The LearningRecordExport data array.
	 *
	 * @return array<string,mixed> The export with coverageReport, bundleRef, bundleSignature,
	 *                             issuerDid and generatedAt set and errorMessage cleared.
	 *
	 * @throws RuntimeException When the bundle can not be composed, signed or stored.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-generation-fails-closed-and-blocks-the-transition-on-error
	 */
	public function generate(array $export): array {
		$tenantId = $this->requireSubject(export: $export);
		$walk = $this->walkLearnerRecord(export: $export);
		$bundle = $this->composeBundle(export: $export, walk: $walk);
		$jws = $this->signBundle(bundle: $bundle, tenantId: $tenantId);
		$bundleRef = $this->storeBundle(signedBundle: $this->withProof(bundle: $bundle, jws: $jws), export: $export);

		$export['coverageReport'] = $walk->coverageReport;
		$export['bundleRef'] = $bundleRef;
		$export['bundleSignature'] = $jws;
		$export['issuerDid'] = $bundle['issuerDid'];
		$export['generatedAt'] = $bundle['generatedAt'];
		$export['errorMessage'] = null;

		return $export;
	}//end generate()

	/**
	 * The export's tenant, once the export names both a learner and a tenant.
	 *
	 * @param array<string,mixed> $export The LearningRecordExport data array.
	 *
	 * @return string The tenant id.
	 *
	 * @throws RuntimeException When the learnerRef or tenant is missing.
	 */
	private function requireSubject(array $export): string {
		$tenantId = (string)($export['tenant_id'] ?? '');
		if ((string)($export['learnerRef'] ?? '') === '' || $tenantId === '') {
			throw new RuntimeException(self::MISSING_SUBJECT);
		}

		return $tenantId;
	}//end requireSubject()

	/**
	 * Compose the learner's record, walk it against the requested period, and
	 * name the staff professional-judgment records that fall within the
	 * requested scope but are deliberately never included — visible, not
	 * silent, per design.md.
	 *
	 * @param array<string,mixed> $export The LearningRecordExport data array.
	 *
	 * @return LearningRecordWalk The finished walk.
	 */
	private function walkLearnerRecord(array $export): LearningRecordWalk {
		$composition = $this->aggregationService->compose(learnerRef: (string)($export['learnerRef'] ?? ''));
		$period = new LearningRecordPeriod(from: ($export['periodFrom'] ?? null), to: ($export['periodTo'] ?? null));
		$walk = $this->walkComposition(composition: $composition, period: $period);

		foreach ($this->findExcludedRowsInScope(learnerId: (string)($export['learnerId'] ?? ''), period: $period) as $excludedEntry) {
			$walk->coverageReport[] = $excludedEntry;
		}

		return $walk;
	}//end walkLearnerRecord()

	/**
	 * Build the unsigned bundle: the issuer, the generation instant, the
	 * requested scope, the ELM section and the lossless scholiqNative section.
	 *
	 * @param array<string,mixed> $export The LearningRecordExport data array.
	 * @param LearningRecordWalk  $walk   The finished walk.
	 *
	 * @return array<string,mixed> The bundle as it is signed.
	 *
	 * @throws RuntimeException When the tenant has no signing key.
	 */
	private function composeBundle(array $export, LearningRecordWalk $walk): array {
		$elm = $this->buildElmSection(credentials: $walk->scholiqNative['credentials'] ?? []);

		return [
			'bundleType' => 'scholiq-learning-record',
			'issuerDid' => $this->requireIssuerDid(tenantId: (string)($export['tenant_id'] ?? '')),
			'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
			'learnerRef' => (string)($export['learnerRef'] ?? ''),
			'periodFrom' => $export['periodFrom'] ?? null,
			'periodTo' => $export['periodTo'] ?? null,
			'elm' => $elm,
			'scholiqNative' => $walk->scholiqNative,
		];
	}//end composeBundle()

	/**
	 * The tenant's issuer DID.
	 *
	 * @param string $tenantId Tenant id.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the tenant has no signing key.
	 */
	private function requireIssuerDid(string $tenantId): string {
		$issuerDid = $this->signingService->resolveIssuerDid(tenantId: $tenantId);
		if ($issuerDid === null) {
			throw new RuntimeException(self::MISSING_KEY);
		}

		return $issuerDid;
	}//end requireIssuerDid()

	/**
	 * Sign the bundle with the tenant's key.
	 *
	 * @param array<string,mixed> $bundle   The unsigned bundle.
	 * @param string              $tenantId Tenant id.
	 *
	 * @return string The JWS.
	 *
	 * @throws RuntimeException When signing fails.
	 */
	private function signBundle(array $bundle, string $tenantId): string {
		$jws = $this->signingService->sign(bundle: $bundle, tenantId: $tenantId);
		if ($jws === null) {
			throw new RuntimeException('Signing the export bundle failed.');
		}

		return $jws;
	}//end signBundle()

	/**
	 * The stored/downloadable artifact is fully self-contained: the signed
	 * payload PLUS its own proof, so a third party can verify it without
	 * calling Learniq. Mirrors Credential.openbadges3Payload embedding its own
	 * `proof` block after signing (CredentialSigningService::check()) — the
	 * signing input itself never includes `proof`.
	 *
	 * @param array<string,mixed> $bundle The signed bundle.
	 * @param string              $jws    Its JWS.
	 *
	 * @return array<string,mixed> The bundle with its proof.
	 */
	private function withProof(array $bundle, string $jws): array {
		$bundle['proof'] = [
			'type' => 'DataIntegrityProof',
			'cryptosuite' => 'rsa-signature-2025',
			'created' => $bundle['generatedAt'],
			'verificationMethod' => $bundle['issuerDid'],
			'proofPurpose' => 'assertionMethod',
			'jws' => $jws,
		];

		return $bundle;
	}//end withProof()

	/**
	 * Store the signed artifact in the learner's (or else the requester's) files.
	 *
	 * @param array<string,mixed> $signedBundle The bundle with its proof.
	 * @param array<string,mixed> $export       The LearningRecordExport data array.
	 *
	 * @return string The bundleRef.
	 *
	 * @throws RuntimeException When the bundle can not be stored.
	 */
	private function storeBundle(array $signedBundle, array $export): string {
		$exportId = (string)($export['id'] ?? ($export['uuid'] ?? bin2hex(random_bytes(8))));

		$bundleRef = $this->bundleWriter->write(
			bundle: $signedBundle,
			ownerUid: $this->bundleOwner(export: $export),
			tenantId: (string)($export['tenant_id'] ?? ''),
			exportId: $exportId
		);
		if ($bundleRef === null) {
			throw new RuntimeException('Could not store the signed bundle.');
		}

		return $bundleRef;
	}//end storeBundle()

	/**
	 * The user whose files hold the bundle: the learner, or else the requester.
	 *
	 * @param array<string,mixed> $export The LearningRecordExport data array.
	 *
	 * @return string Nextcloud user id, '' when neither is known.
	 */
	private function bundleOwner(array $export): string {
		$ownerUid = (string)($export['requestedBy'] ?? '');
		$learnerId = (string)($export['learnerId'] ?? '');
		if ($learnerId !== '') {
			$ownerUid = $learnerId;
		}

		return $ownerUid;
	}//end bundleOwner()

	/**
	 * Walk the composed collections, deciding per row whether it is exported,
	 * and record every row's outcome in the coverage report.
	 *
	 * Nothing is ever dropped silently: a row excluded by the period filter
	 * still produces an `omitted` coverage entry naming it.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $composition `LearningRecordAggregationService::compose()` result.
	 * @param LearningRecordPeriod                         $period      The requested period.
	 *
	 * @return LearningRecordWalk
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
	 */
	private function walkComposition(array $composition, LearningRecordPeriod $period): LearningRecordWalk {
		$walk = new LearningRecordWalk(composition: $composition, period: $period);

		foreach (array_keys($composition) as $collectionKey) {
			if ($collectionKey === 'lessonCompletions') {
				$this->walkLessonCompletions(walk: $walk, collectionKey: $collectionKey);
				continue;
			}

			$this->walkDatedCollection(walk: $walk, collectionKey: $collectionKey);
		}

		return $walk;
	}//end walkComposition()

	/**
	 * Lesson completions are already a per-course summary — always
	 * `summarized`, never period-filtered (the summary spans the whole
	 * enrolment).
	 *
	 * @param LearningRecordWalk $walk          The walk in progress.
	 * @param string             $collectionKey The lesson-completions collection key.
	 *
	 * @return void
	 */
	private function walkLessonCompletions(LearningRecordWalk $walk, string $collectionKey): void {
		$rows = $walk->composition[$collectionKey];
		foreach ($rows as $row) {
			$walk->coverageReport[] = $this->lessonCompletionCoverageEntry(row: $row);
		}

		$walk->scholiqNative[$collectionKey] = $rows;
	}//end walkLessonCompletions()

	/**
	 * Keep the rows of one collection that fall within the period, recording
	 * every row's outcome.
	 *
	 * @param LearningRecordWalk $walk          The walk in progress.
	 * @param string             $collectionKey The collection key.
	 *
	 * @return void
	 */
	private function walkDatedCollection(LearningRecordWalk $walk, string $collectionKey): void {
		$schema = $this->schemaForCollectionKey(key: $collectionKey);
		$dateField = self::PERIOD_FIELD_BY_SCHEMA[$schema] ?? null;
		$includedRows = [];

		foreach ($walk->composition[$collectionKey] as $row) {
			$inPeriod = $walk->period->covers(row: $row, dateField: $dateField);
			if ($inPeriod === true) {
				$includedRows[] = $row;
			}

			$walk->coverageReport[] = $this->rowCoverageEntry(schema: $schema, row: $row, included: $inPeriod);
		}

		$walk->scholiqNative[$collectionKey] = $includedRows;
	}//end walkDatedCollection()

	/**
	 * Build the coverage entry for one lesson-completion summary row.
	 *
	 * @param array<string,mixed> $row The per-course lesson-completion summary.
	 *
	 * @return array<string,mixed> Coverage-report entry.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
	 */
	private function lessonCompletionCoverageEntry(array $row): array {
		return [
			'sourceSchema' => 'lesson-completion',
			'sourceId' => (string)($row['courseId'] ?? ''),
			'sourceTitle' => 'Lesson completions — course ' . ($row['courseId'] ?? 'unknown'),
			'outcome' => 'summarized',
			'reason' => 'Summarized per course (count + percentage) — the raw per-lesson log is never exported.',
		];
	}//end lessonCompletionCoverageEntry()

	/**
	 * Build the coverage entry for one source row.
	 *
	 * @param string $schema Schema slug the row belongs to.
	 * @param array<string,mixed> $row The source row.
	 * @param bool $included Whether the row made it into the bundle.
	 *
	 * @return array<string,mixed> Coverage-report entry.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
	 */
	private function rowCoverageEntry(string $schema, array $row, bool $included): array {
		$outcome = 'omitted';
		$reason = 'Outside the requested export period.';
		if ($included === true) {
			$outcome = 'included';
			$reason = null;
		}

		return [
			'sourceSchema' => $schema,
			'sourceId' => (string)($row['id'] ?? ($row['uuid'] ?? '')),
			'sourceTitle' => $this->resolveSourceTitle(schema: $schema, row: $row),
			'outcome' => $outcome,
			'reason' => $reason,
		];
	}//end rowCoverageEntry()

	/**
	 * Map an aggregation collection key back to its OpenRegister schema slug.
	 *
	 * @param string $key Collection key from `LearningRecordAggregationService::compose()`.
	 *
	 * @return string Schema slug.
	 */
	private function schemaForCollectionKey(string $key): string {
		return match ($key) {
			'enrolments' => 'enrolment',
			'finalGrades' => 'final-grade',
			'competencyAttainments' => 'competency-attainment',
			'credentials' => 'credential',
			'portfolios' => 'portfolio',
			'portfolioEntries' => 'portfolio-entry',
			'externalTrainingRecords' => 'external-training-record',
			'bpvPlacements' => 'bpv-placement',
			'werkprocesAssessments' => 'werkproces-assessment',
			'reportCards' => 'report-card',
			default => $key,
		};
	}//end schemaForCollectionKey()

	/**
	 * Resolve a human-readable label for a coverage-report entry, per schema.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $row The source row.
	 *
	 * @return string
	 */
	private function resolveSourceTitle(string $schema, array $row): string {
		return match ($schema) {
			'credential' => 'Credential (' . ($row['kind'] ?? 'unknown') . ')',
			'final-grade' => 'Final grade',
			'competency-attainment' => 'Competency attainment',
			'portfolio', 'portfolio-entry', 'external-training-record' => (string)($row['title'] ?? $schema),
			'bpv-placement' => (string)($row['trainingCompanyName'] ?? 'BPV placement'),
			'werkproces-assessment' => (string)($row['werkprocesLabel'] ?? 'Werkproces assessment'),
			'enrolment' => 'Course enrolment',
			'report-card' => 'Report card',
			'dossier-note' => 'Dossier note',
			'behaviour-incident' => 'Behaviour incident',
			'wellbeing-check-in' => 'Wellbeing check-in',
			default => $schema,
		};
	}//end resolveSourceTitle()

	/**
	 * Build the ELM/Europass-shaped section: per in-scope Credential,
	 * `edciPayload` when non-empty else `openbadges3Payload`, verbatim — the
	 * identical fallback `WalletOfferDelegationService::buildOfferRequest()`
	 * already uses. Never re-signs or re-derives a credential payload.
	 *
	 * @param array<int,array<string,mixed>> $credentials In-scope Credential rows.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-export-never-bypasses-or-duplicates-the-eudi-wallet-push
	 */
	private function buildElmSection(array $credentials): array {
		$elm = [];
		foreach ($credentials as $credential) {
			$payload = $credential['edciPayload'] ?? null;
			if (empty($payload) === true) {
				$payload = $credential['openbadges3Payload'] ?? null;
			}

			if (empty($payload) === true) {
				continue;
			}

			$elm[] = [
				'credentialId' => $credential['id'] ?? ($credential['uuid'] ?? null),
				'kind' => $credential['kind'] ?? null,
				'payload' => $payload,
			];
		}

		return $elm;
	}//end buildElmSection()

	/**
	 * Find DossierNote/BehaviourIncident/WellbeingCheckIn rows for this
	 * learner that fall within the requested period (or, when no period is
	 * requested, unconditionally — the honest-coverage default), producing
	 * one `omitted` coverageReport entry each. Never included in the bundle.
	 *
	 * @param string               $learnerId Nextcloud user id of the learner.
	 * @param LearningRecordPeriod $period    The requested period.
	 *
	 * @return array<int,array<string,mixed>> coverageReport-shaped entries.
	 */
	private function findExcludedRowsInScope(string $learnerId, LearningRecordPeriod $period): array {
		if ($learnerId === '') {
			return [];
		}

		$entries = [];
		foreach (self::EXCLUDED_SCHEMA_DATE_FIELD as $schema => $dateField) {
			foreach ($this->findLearnerRows(schema: $schema, learnerId: $learnerId) as $row) {
				$data = $this->rowData(row: $row);
				if ($period->covers(row: $data, dateField: $dateField) === false) {
					continue;
				}

				$entries[] = $this->excludedCoverageEntry(schema: $schema, row: $data);
			}
		}

		return $entries;
	}//end findExcludedRowsInScope()

	/**
	 * Every row of one schema recorded for the learner.
	 *
	 * @param string $schema    Schema slug.
	 * @param string $learnerId Nextcloud user id of the learner.
	 *
	 * @return array<int,mixed> Rows as the object service returns them.
	 */
	private function findLearnerRows(string $schema, string $learnerId): array {
		return $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => $schema,
					'learnerId' => $learnerId,
				],
			]
		);
	}//end findLearnerRows()

	/**
	 * A found row as a data array; anything that is neither an array nor
	 * serialisable reads as an empty row.
	 *
	 * @param mixed $row Row as the object service returns it.
	 *
	 * @return array<string,mixed>
	 */
	private function rowData(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end rowData()

	/**
	 * Build the `omitted` coverage entry for one staff professional-judgment row.
	 *
	 * @param string              $schema Schema slug.
	 * @param array<string,mixed> $row    The row data.
	 *
	 * @return array<string,mixed> Coverage-report entry.
	 */
	private function excludedCoverageEntry(string $schema, array $row): array {
		return [
			'sourceSchema' => $schema,
			'sourceId' => (string)($row['id'] ?? ($row['uuid'] ?? '')),
			'sourceTitle' => $this->resolveSourceTitle(schema: $schema, row: $row),
			'outcome' => 'omitted',
			'reason' => 'staff professional-judgment record, not learner-portable evidence',
		];
	}//end excludedCoverageEntry()
}//end class
