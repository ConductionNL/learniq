<?php

/**
 * Learniq Exchange Import Landing
 *
 * Takes the records integriq hands back for an import job
 * (ExchangeRecordsReceivedEvent, integriq exchange-import-landing) and lands
 * them where they belong:
 *
 * - `lvs-results`: an LvsResult in `imported`, waiting for a coordinator to
 *   verify it. Upserted on learner, provider, instrument and moment; a result
 *   that is already verified or archived is left as it is.
 * - `oso`: an OsoImportDossier in `received`, held for review. Upserted on the
 *   sending school, the learner's ECK iD and the received time.
 * - `migration-import`: onto the LearnerProfile of the pupil (by ncUserId),
 *   filling only fields the profile does not have yet; a new pupil gets a new
 *   profile.
 *
 * Runs inside integriq's background job, so every read and write is
 * system-level (no RBAC, no session). A rejection names a code and field
 * names, never a value: integriq stores it as a dead letter.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Lands received import records.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
 */
class ExchangeImportLanding {

	/**
	 * The targets this service lands.
	 */
	public const TARGETS = ['lvs-results', 'oso', 'migration-import'];

	private const REGISTER = 'learniq';

	/**
	 * Fields a migration record may fill on a LearnerProfile. Identity numbers
	 * (BSN, onderwijsnummer) and roles are not taken from a migration file.
	 */
	private const PROFILE_FIELDS = [
		'givenName',
		'familyName',
		'birthDate',
		'eckId',
		'schoolId',
		'department',
		'address',
		'emergencyContacts',
		'medicalConditions',
		'allergies',
	];

	/**
	 * Fields an LVS record may carry.
	 */
	private const LVS_FIELDS = [
		'provider',
		'instrument',
		'moment',
		'takenAt',
		'rawScore',
		'vaardigheidsscore',
		'niveau',
		'referentieniveau',
		'dle',
		'learnerId',
	];

	/**
	 * Fields an OSO record may carry.
	 */
	private const OSO_FIELDS = ['sourceSchoolBrin', 'learnerEckId', 'receivedAt', 'categories', 'draftProfile', 'attachmentRefs'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param ITimeFactory $time The receiving time for a dossier without one.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Land a job's records.
	 *
	 * @param string $target The exchange target.
	 * @param string $jobId The integriq job uuid.
	 * @param array<string, mixed> $scope The job's scope (`tenantId`).
	 * @param array<int, array<string, mixed>> $records Each `{recordId, sourceKind, data}`.
	 *
	 * @return array{accepted: int, rejected: array<int, array{recordId: string, errorCode: string, offendingFields: array<int, string>}>}
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function land(string $target, string $jobId, array $scope, array $records): array {
		$accepted = 0;
		$rejected = [];
		$tenantId = (string)($scope['tenantId'] ?? '');
		foreach ($records as $record) {
			$recordId = (string)($record['recordId'] ?? '');
			$data = $record['data'] ?? null;
			if ($recordId === '' || is_array($data) === false) {
				continue;
			}

			$data['tenant_id'] = (string)($data['tenant_id'] ?? $tenantId);
			try {
				$refusal = $this->landOne(target: $target, jobId: $jobId, data: $data);
			} catch (Throwable $exception) {
				$refusal = ['code' => 'IMPORT-WRITE-FAILED', 'fields' => []];
			}

			if ($refusal === null) {
				$accepted++;
				continue;
			}

			$rejected[] = ['recordId' => $recordId, 'errorCode' => $refusal['code'], 'offendingFields' => $refusal['fields']];
		}//end foreach

		return ['accepted' => $accepted, 'rejected' => $rejected];
	}//end land()

	/**
	 * Land one record.
	 *
	 * @param string $target The exchange target.
	 * @param string $jobId The job uuid.
	 * @param array<string, mixed> $data The record in learniq's field names.
	 *
	 * @return array{code: string, fields: array<int, string>}|null The refusal, or null when taken.
	 */
	private function landOne(string $target, string $jobId, array $data): ?array {
		if ($target === 'lvs-results') {
			return $this->landLvs(jobId: $jobId, data: $data);
		}

		if ($target === 'oso') {
			return $this->landOso(jobId: $jobId, data: $data);
		}

		return $this->landMigration(data: $data);
	}//end landOne()

	/**
	 * An LVS result, imported and waiting to be verified.
	 *
	 * @param string $jobId The job uuid.
	 * @param array<string, mixed> $data The record.
	 *
	 * @return array{code: string, fields: array<int, string>}|null
	 */
	private function landLvs(string $jobId, array $data): ?array {
		$missing = $this->missing(data: $data, fields: ['provider', 'instrument', 'moment', 'learnerId']);
		if ($missing !== []) {
			return ['code' => 'LVS-MISSING-FIELD', 'fields' => $missing];
		}

		if ($this->first(schema: 'learner-profile', filters: ['ncUserId' => (string)$data['learnerId']]) === null) {
			return ['code' => 'LVS-UNKNOWN-PUPIL', 'fields' => ['learnerId']];
		}

		$row = $this->pick(data: $data, fields: self::LVS_FIELDS);
		$existing = $this->first(
			schema: 'lvs-result',
			filters: [
				'learnerId' => $row['learnerId'],
				'provider' => $row['provider'],
				'instrument' => $row['instrument'],
				'moment' => $row['moment'],
			]
		);
		if ($existing !== null && in_array((string)($existing['lifecycle'] ?? ''), ['verified', 'archived'], true) === true) {
			// A verified result is frozen (lvs-score-freeze); a second
			// delivery of it changes nothing.
			return null;
		}

		$this->save(
			schema: 'lvs-result',
			row: array_merge($existing ?? [], $row, ['dataExchangeJobId' => $jobId, 'tenant_id' => $data['tenant_id'], 'lifecycle' => 'imported'])
		);
		return null;
	}//end landLvs()

	/**
	 * An OSO dossier, received and held for review.
	 *
	 * @param string $jobId The job uuid.
	 * @param array<string, mixed> $data The record.
	 *
	 * @return array{code: string, fields: array<int, string>}|null
	 */
	private function landOso(string $jobId, array $data): ?array {
		$missing = $this->missing(data: $data, fields: ['sourceSchoolBrin']);
		if ($missing !== []) {
			return ['code' => 'OSO-MISSING-FIELD', 'fields' => $missing];
		}

		$row = $this->pick(data: $data, fields: self::OSO_FIELDS);
		if (($row['receivedAt'] ?? '') === '') {
			$row['receivedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		}

		$key = ['sourceSchoolBrin' => $row['sourceSchoolBrin'], 'receivedAt' => $row['receivedAt']];
		if (($row['learnerEckId'] ?? '') !== '') {
			$key['learnerEckId'] = $row['learnerEckId'];
		}

		if ($this->first(schema: 'oso-import-dossier', filters: $key) !== null) {
			// Delivered before: the dossier is already held for review.
			return null;
		}

		$this->save(
			schema: 'oso-import-dossier',
			row: array_merge($row, ['dataExchangeJobId' => $jobId, 'tenant_id' => $data['tenant_id'], 'status' => 'received'])
		);
		return null;
	}//end landOso()

	/**
	 * A migrated pupil, onto their LearnerProfile.
	 *
	 * @param array<string, mixed> $data The record.
	 *
	 * @return array{code: string, fields: array<int, string>}|null
	 */
	private function landMigration(array $data): ?array {
		$missing = $this->missing(data: $data, fields: ['ncUserId']);
		if ($missing !== []) {
			return ['code' => 'MIGRATION-MISSING-FIELD', 'fields' => $missing];
		}

		$incoming = $this->pick(data: $data, fields: self::PROFILE_FIELDS);
		$existing = $this->first(schema: 'learner-profile', filters: ['ncUserId' => (string)$data['ncUserId']]);
		if ($existing === null) {
			$this->save(
				schema: 'learner-profile',
				row: array_merge($incoming, ['ncUserId' => (string)$data['ncUserId'], 'tenant_id' => $data['tenant_id'], 'lifecycle' => 'active'])
			);
			return null;
		}

		// Never overwrite what the school already holds: fill the gaps only.
		$fill = [];
		foreach ($incoming as $field => $value) {
			if ($this->isEmpty(value: $existing[$field] ?? null) === true) {
				$fill[$field] = $value;
			}
		}

		if ($fill !== []) {
			$this->save(schema: 'learner-profile', row: array_merge($existing, $fill));
		}

		return null;
	}//end landMigration()

	/**
	 * The named fields that are missing or empty.
	 *
	 * @param array<string, mixed> $data The record.
	 * @param array<int, string> $fields The required fields.
	 *
	 * @return array<int, string>
	 */
	private function missing(array $data, array $fields): array {
		return array_values(array_filter($fields, fn (string $field): bool => $this->isEmpty(value: $data[$field] ?? null)));
	}//end missing()

	/**
	 * The known, non-empty fields of a record.
	 *
	 * @param array<string, mixed> $data The record.
	 * @param array<int, string> $fields The known fields.
	 *
	 * @return array<string, mixed>
	 */
	private function pick(array $data, array $fields): array {
		$row = [];
		foreach ($fields as $field) {
			if ($this->isEmpty(value: $data[$field] ?? null) === false) {
				$row[$field] = $data[$field];
			}
		}

		return $row;
	}//end pick()

	/**
	 * Whether a value counts as absent.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isEmpty(mixed $value): bool {
		return $value === null || $value === '' || $value === [];
	}//end isEmpty()

	/**
	 * The first row matching the filters, read without RBAC.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(string $schema, array $filters): ?array {
		$rows = $this->objectService->findAll(
			['filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]), 'limit' => 1],
			false,
			false
		);
		if (empty($rows) === true) {
			return null;
		}

		$row = $rows[0];
		if (is_array($row) === false) {
			$row = $row->jsonSerialize();
		}

		return $row;
	}//end first()

	/**
	 * Write a row as the system.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $row The row.
	 *
	 * @return void
	 */
	private function save(string $schema, array $row): void {
		$this->objectService->saveObject(
			object: $row,
			register: self::REGISTER,
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);
	}//end save()
}//end class
