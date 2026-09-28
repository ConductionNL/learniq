<?php

/**
 * Learniq Data Exchange Payload Builder
 *
 * Composes what may leave learniq for one exchange job: reads the objects the
 * job's scope selects, keeps only the fields the job's integriq mapping reads
 * (ExchangeDisclosure), resolves the BRIN a mapping needs, and runs the
 * leerplicht and SWV file composers that a flat mapping cannot express. The
 * records go to integriq in the gate answer and are never stored there.
 *
 * Learniq applies no mapping any more: the integriq mapping renames the fields
 * (decision D7). No wire protocol lives here.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Builds the records a job may hand to integriq.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.1
 */
class DataExchangePayloadBuilder {
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Target that composes the verzuimloket file (attendance flag plus its
	 * breaching records and interventions).
	 */
	private const LEERPLICHT_TARGET = 'leerplicht';
	private const ATTENDANCE_RECORD_SCHEMA = 'attendance-record';

	/**
	 * Target that composes the SWV care-request file from the support
	 * request's learner and (optional) learning plan.
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 */
	private const SWV_TARGET = 'swv';
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';
	private const LEARNING_PLAN_SCHEMA = 'learning-plan';

	/**
	 * The most objects one job may carry; reaching it fails the composition
	 * rather than handing over a silently truncated set (ADR-058).
	 */
	public const QUERY_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService             $objectService  OR object access service.
	 * @param DataExchangeTransformer   $transformer    Resolves the BRIN a mapping needs.
	 * @param ExchangeDisclosure        $disclosure     What each mapping may read.
	 * @param RodPersonalNumberResolver $personalNumber The pupil's number, for the two ROD mappings only.
	 * @param RodSchoolAdviceComposer   $schoolAdvice   DUO's AanleverenAdviesVO field set.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly DataExchangeTransformer $transformer,
		private readonly ExchangeDisclosure $disclosure,
		private readonly RodPersonalNumberResolver $personalNumber,
		private readonly RodSchoolAdviceComposer $schoolAdvice,
	) {
	}//end __construct()

	/**
	 * Compose the records a job may hand to integriq.
	 *
	 * Each record is `{recordId, sourceKind, data}`. With a field list, data
	 * holds only those fields; without one (a non-statutory target), the object
	 * minus `bsnEncrypted`, `bsnHash` and `email`. The caller refuses a statutory
	 * target without a field list before it gets here.
	 *
	 * @param string               $target      The exchange target.
	 * @param string|null          $mappingSlug The job's integriq mapping.
	 * @param array<string, mixed> $scope       The job's scope (schema, filters, cohortId, recordIds).
	 * @param string               $tenantId    Tenant to force on every read.
	 *
	 * @return array<int, array{recordId: string, sourceKind: string, data: array<string, mixed>}> The records.
	 *
	 * @throws RuntimeException When the scope selects more than QUERY_LIMIT objects.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#requirement-the-personal-number-leaves-learniq-only-in-a-rod-message-and-is-never-logged
	 */
	public function composeRecords(string $target, ?string $mappingSlug, array $scope, string $tenantId): array {
		$schema = (string)($scope['schema'] ?? '');
		$fields = $this->disclosure->fieldsFor(mappingSlug: $mappingSlug);
		$records = [];

		foreach ($this->querySourceObjects(scope: $scope, tenantId: $tenantId) as $object) {
			$recordId = (string)($object['id'] ?? ($object['uuid'] ?? ''));
			$records[] = [
				'recordId' => $recordId,
				'sourceKind' => $schema,
				'data' => $this->composeData(
					target: $target,
					mappingSlug: $mappingSlug,
					fields: $fields,
					object: $object,
					tenantId: $tenantId
				),
			];
		}

		return $records;
	}//end composeRecords()

	/**
	 * Compose one record's data, adding the personal number only for the two ROD mappings.
	 *
	 * @param string                  $target      The exchange target.
	 * @param string|null             $mappingSlug The job's integriq mapping.
	 * @param array<int, string>|null $fields      The mapping's field list.
	 * @param array<string, mixed>    $object      The source object.
	 * @param string                  $tenantId    The job's tenant.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#requirement-the-rod-learner-record-carries-the-personal-number-where-duo-expects-a-bsn
	 */
	private function composeData(string $target, ?string $mappingSlug, ?array $fields, array $object, string $tenantId): array {
		$carries = $this->disclosure->carriesPersonalNumber(target: $target, mappingSlug: $mappingSlug);
		if ($carries === true && $mappingSlug === ExchangeDisclosure::ROD_SCHOOL_ADVICE_MAPPING) {
			return $this->schoolAdvice->compose(advice: $object, tenantId: $tenantId);
		}

		$data = $this->composeFile(record: $this->disclose(object: $object, fields: $fields), source: $object, target: $target);
		unset($data[RodPersonalNumberResolver::NUMBER_KEY], $data[RodPersonalNumberResolver::TYPE_KEY]);
		if ($carries === true) {
			$data = array_merge(
				$data,
				$this->personalNumber->forProfile(profileId: (string)($object['id'] ?? ($object['uuid'] ?? '')), tenantId: $tenantId)
			);
		}

		return $data;
	}//end composeData()

	/**
	 * Keep what may leave of one object.
	 *
	 * @param array<string, mixed>   $object The source object.
	 * @param array<int, string>|null $fields The mapping's field list, or null for pass-through.
	 *
	 * @return array<string, mixed> The disclosed fields.
	 */
	private function disclose(array $object, ?array $fields): array {
		if ($fields === null) {
			foreach (ExchangeDisclosure::NEVER as $never) {
				unset($object[$never]);
			}

			unset($object['@self']);
			return $object;
		}

		$data = [];
		foreach ($fields as $field) {
			if ($field === 'schoolBrin') {
				$data['schoolBrin'] = $this->transformer->applyTransform(
					value: ($object['cohortId'] ?? null),
					transform: 'cohort-to-brin',
					object: $object
				);
				continue;
			}

			$data[$field] = ($object[$field] ?? null);
		}

		return $data;
	}//end disclose()

	/**
	 * The objects a job's scope selects, tenant-forced and bounded.
	 *
	 * @param array<string, mixed> $scope    The job scope.
	 * @param string               $tenantId Tenant to force on the read.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @throws RuntimeException When the read reaches QUERY_LIMIT.
	 */
	private function querySourceObjects(array $scope, string $tenantId): array {
		$schema = (string)($scope['schema'] ?? '');
		if ($schema === '') {
			return [];
		}

		$filters = $scope['filters'] ?? [];
		if (is_array($filters) === false) {
			$filters = [];
		}

		$cohortId = $scope['cohortId'] ?? null;
		if (is_string($cohortId) === true && $cohortId !== '') {
			$filters['cohortId'] = $cohortId;
		}

		// #186: always force tenant_id so a scope naming another tenant reads nothing.
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'filters' => array_merge($filters, ['register' => self::LEARNIQ_REGISTER, 'schema' => $schema]),
				'limit' => self::QUERY_LIMIT,
			]
		);

		if (count($results) >= self::QUERY_LIMIT) {
			throw new RuntimeException(
				'The exchange scope selects ' . self::QUERY_LIMIT . " or more '{$schema}' objects; narrow it so nothing is cut off."
			);
		}

		$objects = array_map(
			static fn ($item): array => self::rowOf(object: $item),
			$results
		);

		$wanted = $scope['recordIds'] ?? null;
		if (is_array($wanted) === false || $wanted === []) {
			return array_values($objects);
		}

		$wanted = array_map('strval', $wanted);
		return array_values(
			array_filter(
				$objects,
				static fn (array $object): bool => in_array((string)($object['id'] ?? ($object['uuid'] ?? '')), $wanted, true)
			)
		);
	}//end querySourceObjects()

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

		// Always present, null without a plan: integriq's mapping copies the key, and an
		// absent key would be rendered as its own name instead.
		$record['learningPlanContext'] = null;

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
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::LEARNER_PROFILE_SCHEMA,
					]
				),
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
		$filters = [];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'ids' => [$learningPlanId],
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::LEARNING_PLAN_SCHEMA,
					]
				),
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

			$filters = [];
			if ($tenantId !== '') {
				$filters['tenant_id'] = $tenantId;
			}

			$results = $this->objectService->findAll(
				[
					'ids' => [$id],
					'filters' => array_merge(
						$filters,
						[
							'register' => self::LEARNIQ_REGISTER,
							'schema' => self::ATTENDANCE_RECORD_SCHEMA,
						]
					),
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

	/**
	 * One OpenRegister result as a plain row.
	 *
	 * @param mixed $object An array or an object entity.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function rowOf(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		return (array)$object->jsonSerialize();
	}//end rowOf()
}//end class
