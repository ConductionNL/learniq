<?php

/**
 * Scholiq Data Exchange Payload Builder
 *
 * Turns the Scholiq source objects a `DataExchangeJob`'s scope selected into
 * the payload OpenConnector receives: applies the `DataMappingProfile`'s
 * fieldMappings (via `DataExchangeTransformer`), strips PII, stamps the
 * correlation identifier, and runs the per-target dossier composers
 * (leerplicht/verzuimloket and swv/OSO care-request) that a flat mapping
 * cannot express.
 *
 * Extracted out of `DataExchangeRunHandler` so that listener stays what its
 * own docblock claims — an orchestrator of the OpenConnector call — and so
 * both classes stay within this app's PHPMD complexity and length budget.
 * No wire protocol lives here; all Edukoppeling/StUF/OSO-XML/Digikoppeling
 * logic remains in OpenConnector (ADR-031 "external-system bridge").
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Builds the OpenConnector payload for a data-exchange run.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.1
 */
class DataExchangePayloadBuilder {
	private const SCHOLIQ_REGISTER = 'scholiq';

	/**
	 * Target that composes the verzuimloket dossier (attendance-flag +
	 * breachingRecordIds + interventions) instead of the flat fieldMappings
	 * export. Mirrors the "OSO dossier composer" pattern described in the
	 * data-exchange spec's "What" section.
	 */
	private const LEERPLICHT_TARGET = 'leerplicht';
	private const ATTENDANCE_RECORD_SCHEMA = 'attendance-record';

	/**
	 * Target that composes the SWV zorgvraag care-request dossier from the
	 * originating SupportRequest's linked LearnerProfile + (optional)
	 * LearningPlan, mirroring the leerplicht dossier composer above and the
	 * OSO overstapdossier pattern the data-exchange spec describes. See
	 * composeSwvDossier() for the minimal-disclosure whitelist.
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 */
	private const SWV_TARGET = 'swv';
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';
	private const LEARNING_PLAN_SCHEMA = 'learning-plan';

	/**
	 * Per-target allowlist of mandatory profile slugs.
	 * When a target is listed here, a null profile (no data mapping) is a hard failure
	 * rather than pass-through, to prevent unredacted PII from being shipped (C3).
	 *
	 * Fixes a pre-existing mismatch: this list previously named 'oso-transfer',
	 * which is never an actual DataExchangeJob.target value (the real target
	 * string is 'oso' — see the "OSO transfer dossier" DataMappingProfile seed
	 * and DataExchangeJob.target's own description). That meant an 'oso' job
	 * with no configured profile silently fell through to the PII-stripped
	 * pass-through branch instead of hard-failing — a real gap for a
	 * discretionary, consent-gated transfer. Corrected here to 'oso' while
	 * adding 'swv' (openspec/changes/zorgvraag-swv-tlv-chain design.md
	 * "Minimal disclosure via DataMappingProfile whitelist, not object-level
	 * ACLs" — fail-closed: an unset profile MUST yield no export, never a
	 * wider one).
	 *
	 * @var string[]
	 */
	private const MANDATORY_PROFILE_TARGETS = ['bron-rod', 'bron-vo', 'oso', 'edukoppeling', 'swv'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param DataExchangeTransformer $transformer Named field-transform applier.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly DataExchangeTransformer $transformer,
	) {
	}//end __construct()

	/**
	 * Build the payload array for OpenConnector from source objects and an optional mapping profile.
	 *
	 * Applies field mappings from the profile when present; falls back to a PII-stripped
	 * pass-through when the profile is absent. Targets in MANDATORY_PROFILE_TARGETS throw
	 * a RuntimeException when no profile is provided (C3 — prevents unredacted PII export).
	 *
	 * @param array<int,array<string,mixed>> $objects Source objects retrieved from OR.
	 * @param array<string,mixed>|null $profile Loaded DataMappingProfile, or null for pass-through.
	 * @param string $target Data-exchange target slug (e.g. 'bron-rod').
	 *
	 * @return array<int,array<string,mixed>> Mapped (and PII-stripped) payload ready for OpenConnector.
	 *
	 * @throws \RuntimeException When the target requires a profile but none is configured.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 */
	public function buildPayload(array $objects, ?array $profile, string $target = ''): array {
		// C3: for targets that require a mapping profile, null profile is a hard fail.
		if ($profile === null && in_array($target, self::MANDATORY_PROFILE_TARGETS, strict: true) === true) {
			throw new RuntimeException(
				"Data exchange target '{$target}' requires a DataMappingProfile but none is configured — "
				. 'aborting to prevent unredacted PII export.'
			);
		}

		if ($profile === null || empty($profile['fieldMappings']) === true) {
			return $this->passThroughPayload(objects: $objects, target: $target);
		}

		$fieldMappings = $profile['fieldMappings'];
		$payload = [];

		foreach ($objects as $object) {
			$record = $this->mapRecord(object: $object, fieldMappings: $fieldMappings);

			// C3: always strip PII fields from the mapped record even when profile is present.
			unset($record['bsnEncrypted'], $record['bsnHash'], $record['email']);

			// Re-assert the correlation stamp: guarantee it survives even if a
			// (misconfigured) fieldMappings entry names '_scholiqRecordId' as its
			// own targetField — this stamp must always equal the source object's id.
			$record['_scholiqRecordId'] = ($object['id'] ?? ($object['uuid'] ?? ''));

			$payload[] = $this->composeFile(record: $record, source: $object, target: $target);
		}

		return $payload;
	}//end buildPayload()

	/**
	 * Build the payload for a target that has no mapping profile: the raw source
	 * objects with PII fields explicitly stripped (C3).
	 *
	 * @param array<int,array<string,mixed>> $objects Source objects retrieved from OR.
	 * @param string $target Data-exchange target slug.
	 *
	 * @return array<int,array<string,mixed>> PII-stripped pass-through payload.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 */
	private function passThroughPayload(array $objects, string $target): array {
		$payload = [];
		foreach ($objects as $object) {
			// Correlation stamp (duo-afkeurmelding-correction): every record carries
			// the source object's own id BEFORE composition, so a rejection returned
			// in a later job's result.validationReport can be resolved back to the
			// Scholiq object that produced it. Stamped first so the leerplicht/swv
			// composers never strip it (they only add keys, never unset()).
			$object['_scholiqRecordId'] = ($object['id'] ?? ($object['uuid'] ?? ''));

			unset($object['bsnEncrypted'], $object['bsnHash'], $object['email']);

			$payload[] = $this->composeFile(record: $object, source: $object, target: $target);
		}

		return $payload;
	}//end passThroughPayload()

	/**
	 * Apply a profile's field mappings to one source object.
	 *
	 * @param array<string,mixed> $object The source object.
	 * @param array<int,array<string,mixed>> $fieldMappings The profile's fieldMappings entries.
	 *
	 * @return array<string,mixed> The mapped record, already carrying its correlation stamp.
	 *
	 * @throws \RuntimeException When a transform refuses to produce a value (e.g. missing eckId).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 */
	private function mapRecord(array $object, array $fieldMappings): array {
		// Correlation stamp (duo-afkeurmelding-correction): the source object's own id,
		// written before the fieldMappings loop so it is never a caller-mappable target
		// field and always survives dossier composition.
		$record = ['_scholiqRecordId' => ($object['id'] ?? ($object['uuid'] ?? ''))];

		foreach ($fieldMappings as $mapping) {
			$scholiqField = $mapping['scholiqField'] ?? '';
			$targetField = $mapping['targetField'] ?? '';

			if ($scholiqField === '' || $targetField === '') {
				continue;
			}

			$record[$targetField] = $this->transformer->applyTransform(
				value: ($object[$scholiqField] ?? null),
				transform: ($mapping['transform'] ?? null),
				object: $object
			);
		}

		return $record;
	}//end mapRecord()

	/**
	 * Run the target's dossier composer over a record, when it has one.
	 *
	 * A flat `fieldMappings` entry cannot resolve a `$ref` into a nested payload
	 * section, so the leerplicht (Verzuimloket) and swv (OSO care-request) targets assemble their
	 * dossier from the originating object instead of a bare flat mapping. Every
	 * other target passes the record straight through.
	 *
	 * @param array<string,mixed> $record The mapped (or pass-through) record.
	 * @param array<string,mixed> $source The originating source object.
	 * @param string $target Data-exchange target slug.
	 *
	 * @return array<string,mixed> The composed record.
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 */
	private function composeFile(array $record, array $source, string $target): array {
		return match ($target) {
			self::LEERPLICHT_TARGET => $this->composeLeerplichtFile(record: $record, flag: $source),
			self::SWV_TARGET => $this->composeSwvFile(record: $record, supportRequest: $source),
			default => $record,
		};

	}//end composeDossier()

	/**
	 * Compose the verzuimloket dossier for a leerplicht-target report.
	 *
	 * Mirrors the "OSO dossier composer" pattern described in the data-exchange
	 * spec's "What" section: assembles the dossier from the originating
	 * AttendanceFlag's own data plus its linked AttendanceRecords, rather than
	 * shipping only the flat scalar fields the DataMappingProfile.fieldMappings
	 * mechanism can express (it has no facility to resolve a $ref array into a
	 * nested payload section). `interventions` requires no extra resolution —
	 * it is already a plain property on the AttendanceFlag object queried by
	 * querySourceObjects, so it flows through untouched.
	 *
	 * Unlike the OSO/SWV dossiers, this composition does NOT gate on
	 * pending-parent-review (see DataExchangeRunGuard — it only blocks
	 * target=oso); the leerplicht report is a mandatory Leerplichtwet art. 21a
	 * report, not a discretionary transfer.
	 *
	 * @param array<string,mixed> $record The flat field-mapped (or pass-through)
	 *                                    record built so far.
	 * @param array<string,mixed> $flag The source AttendanceFlag object.
	 *
	 * @return array<string,mixed> $record with breachingRecords + interventions appended.
	 *
	 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.1
	 */
	private function composeLeerplichtFile(array $record, array $flag): array {
		$breachingRecordIds = $flag['breachingRecordIds'] ?? [];
		if (is_array($breachingRecordIds) === false) {
			$breachingRecordIds = [];
		}

		$record['breachingRecords'] = $this->resolveAttendanceRecords(
			ids: $breachingRecordIds,
			tenantId: (string)($flag['tenant_id'] ?? '')
		);

		$interventions = $flag['interventions'] ?? [];
		if (is_array($interventions) === false) {
			$interventions = [];
		}

		$record['interventions'] = $interventions;

		return $record;
	}//end composeLeerplichtDossier()

	/**
	 * Compose the SWV zorgvraag care-request dossier for a swv-target job.
	 *
	 * Mirrors the "OSO dossier composer" pattern described in the
	 * data-exchange spec's "What" section and composeLeerplichtDossier()
	 * above: assembles the dossier from the originating SupportRequest's
	 * linked LearnerProfile and (when set) LearningPlan, rather than shipping
	 * only the flat scalar fields the DataMappingProfile.fieldMappings
	 * mechanism can express (it has no facility to resolve a $ref into a
	 * nested payload section).
	 *
	 * Minimal disclosure (openspec/changes/zorgvraag-swv-tlv-chain/design.md
	 * "Minimal disclosure via DataMappingProfile whitelist, not object-level
	 * ACLs"): both the `learner` and `learningPlanContext` sections below are
	 * built from an EXPLICIT field whitelist, never a full-object dump —
	 * `LearnerProfile.bsnEncrypted`/`bsnHash`/`email` are never read here, and
	 * the LearningPlan section carries only the fields the SWV needs as
	 * deliberation context (goals/support measures/kind/period), never
	 * internal linkage fields like templateId/cohortId/courseId/coordinatorId.
	 *
	 * Fail-closed: absent `learningPlanId` on the SupportRequest yields no
	 * `learningPlanContext` section at all (never a wider export); an
	 * unresolvable LearnerProfile yields a null `learner` section rather than
	 * inventing data.
	 *
	 * @param array<string,mixed> $record The flat field-mapped record built so far.
	 * @param array<string,mixed> $supportRequest The source SupportRequest object.
	 *
	 * @return array<string,mixed> $record with learner + learningPlanContext appended.
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-minimal-disclosure-to-the-swv-via-a-field-whitelisting-datamappingprofile
	 */
	private function composeSwvFile(array $record, array $supportRequest): array {
		$learnerId = (string)($supportRequest['learnerId'] ?? '');
		$tenantId = (string)($supportRequest['tenant_id'] ?? '');

		$record['learner'] = $this->resolveLearnerWhitelist(learnerId: $learnerId, tenantId: $tenantId);

		$learningPlanId = $supportRequest['learningPlanId'] ?? null;
		if (is_string($learningPlanId) === true && $learningPlanId !== '') {
			$record['learningPlanContext'] = $this->resolveLearningPlanWhitelist(
				learningPlanId: $learningPlanId,
				tenantId: $tenantId
			);
		}

		return $record;
	}//end composeSwvDossier()

	/**
	 * Resolve a learner's LearnerProfile to the minimal-disclosure whitelist
	 * of fields the OSO care-request dossier needs. NEVER includes
	 * bsnEncrypted, bsnHash, or email (design.md "No BSN exposure").
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<string,mixed>|null Whitelisted learner fields, or null when unresolvable.
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 */
	private function resolveLearnerWhitelist(string $learnerId, string $tenantId): ?array {
		if ($learnerId === '') {
			return null;
		}

		$filters = ['ncUserId' => $learnerId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::LEARNER_PROFILE_SCHEMA,
				'filters' => $filters,
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		$profile = $results[0];
		if (is_array($results[0]) === false) {
			$profile = $results[0]->jsonSerialize();
		}

		// Explicit whitelist — never bsnEncrypted/bsnHash/email.
		return [
			'eckId' => $profile['eckId'] ?? null,
			'givenName' => $profile['givenName'] ?? null,
			'familyName' => $profile['familyName'] ?? null,
			'birthDate' => $profile['birthDate'] ?? null,
			'schoolId' => $profile['schoolId'] ?? null,
		];

	}//end resolveLearnerWhitelist()

	/**
	 * Resolve a LearningPlan to the minimal-disclosure whitelist of context
	 * fields the SWV needs for deliberation (goals/support measures/kind/
	 * period) — never internal linkage fields (templateId/cohortId/courseId/
	 * coordinatorId).
	 *
	 * @param string $learningPlanId UUID of the LearningPlan.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<string,mixed>|null Whitelisted plan context, or null when unresolvable.
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 */
	private function resolveLearningPlanWhitelist(string $learningPlanId, string $tenantId): ?array {
		$filters = ['id' => $learningPlanId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::LEARNING_PLAN_SCHEMA,
				'filters' => $filters,
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		$plan = $results[0];
		if (is_array($results[0]) === false) {
			$plan = $results[0]->jsonSerialize();
		}

		return [
			'kind' => $plan['kind'] ?? null,
			'period' => $plan['period'] ?? null,
			'goals' => $plan['goals'] ?? [],
			'supportMeasures' => $plan['supportMeasures'] ?? [],
		];

	}//end resolveLearningPlanWhitelist()

	/**
	 * Resolve a flag's breachingRecordIds to their full AttendanceRecord data.
	 *
	 * @param array<int,mixed> $ids UUIDs of AttendanceRecords to resolve.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<int,array<string,mixed>> Resolved AttendanceRecord objects, PII-stripped.
	 *
	 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.1
	 */
	private function resolveAttendanceRecords(array $ids, string $tenantId): array {
		$records = [];

		foreach ($ids as $id) {
			if (is_string($id) === false || $id === '') {
				continue;
			}

			$filters = ['id' => $id];
			if ($tenantId !== '') {
				$filters['tenant_id'] = $tenantId;
			}

			$results = $this->objectService->findAll(
				[
					'register' => self::SCHOLIQ_REGISTER,
					'schema' => self::ATTENDANCE_RECORD_SCHEMA,
					'filters' => $filters,
					'limit' => 1,
				]
			);

			if (empty($results) === true) {
				continue;
			}

			$recordData = $results[0];
			if (is_array($results[0]) === false) {
				$recordData = $results[0]->jsonSerialize();
			}

			unset($recordData['bsnEncrypted'], $recordData['bsnHash'], $recordData['email']);

			$records[] = $recordData;
		}//end foreach

		return $records;
	}//end resolveAttendanceRecords()
}//end class
