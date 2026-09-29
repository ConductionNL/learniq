<?php

/**
 * Learniq ROD School Advice Composer
 *
 * Composes one school advice as DUO's AanleverenAdviesVO field set, and
 * nothing else from the advice or the pupil dossier (decision D32).
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * DUO PvE ROD-PO versie 1.14.2 (15-4-2026), section 7.9.1 AanleverenAdviesVO_Request.
 *
 * Advies1 is the voorlopig advice, Advies2 the definitief advice, which after
 * a heroverweging is the reconsidered advice. The heroverweging motivation and
 * the doorstroomtoets result never leave. A value that does not fit DUO's
 * format is null, so the gate's completeness check refuses the job naming the
 * field instead of DUO rejecting the message.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
 */
class RodSchoolAdviceComposer {

	private const LEARNIQ_REGISTER = 'learniq';
	private const VESTIGING_SCHEMA = 'vestiging';
	private const SCHOOL_SCHEMA = 'school';

	/**
	 * Learniq's advice ordinal to DUO's AdviesVO value list (7.9.1.2).
	 *
	 * @var array<string, string>
	 */
	public const LEVELS = [
		'pro' => 'PRAKTIJKONDERWIJS',
		'vmbo-bb' => 'VMBO_BB',
		'vmbo-kb' => 'VMBO_KB',
		'vmbo-gt' => 'VMBO_GL/TL',
		'havo' => 'HAVO',
		'vwo' => 'VWO',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService             $objectService  OR object access.
	 * @param RodPersonalNumberResolver $personalNumber Reads the pupil's number.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly RodPersonalNumberResolver $personalNumber,
	) {
	}//end __construct()

	/**
	 * Compose one advice.
	 *
	 * @param array<string, mixed> $advice   The SchoolAdvies row.
	 * @param string               $tenantId The job's tenant.
	 *
	 * @return array<string, mixed> Exactly ExchangeDisclosure::ROD_SCHOOL_ADVICE_FIELDS.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
	 */
	public function compose(array $advice, string $tenantId): array {
		$vestiging = $this->vestigingOf(advice: $advice, tenantId: $tenantId);
		$school = $this->row(schema: self::SCHOOL_SCHEMA, uuid: (string)($vestiging['schoolId'] ?? ''), tenantId: $tenantId);
		$person = $this->personalNumber->forLearner(ncUserId: (string)($advice['learnerId'] ?? ''), tenantId: $tenantId);

		$record = [
			RodPersonalNumberResolver::NUMBER_KEY => $person[RodPersonalNumberResolver::NUMBER_KEY],
			RodPersonalNumberResolver::TYPE_KEY => $person[RodPersonalNumberResolver::TYPE_KEY],
			'adviesvolgnummer' => self::sequenceNumber(uuid: (string)($advice['id'] ?? ($advice['uuid'] ?? ''))),
			'onderwijsaanbieder' => self::matching(value: $school['onderwijsaanbiedercode'] ?? null, pattern: '/^[0-9]{3}A[0-9]{3}$/'),
			'onderwijslocatie' => self::matching(value: $vestiging['onderwijslocatiecode'] ?? null, pattern: '/^[0-9]{3}X[0-9]{3}$/'),
			'vestigingscode' => self::matching(value: $vestiging['vestigingscode'] ?? null, pattern: '/^[0-9A-Za-z]{6}$/'),
			'adviesjaar' => self::adviceYear(academicYear: (string)($advice['academicYear'] ?? '')),
			'advies1' => (self::LEVELS[(string)($advice['voorlopigAdviesLevel'] ?? '')] ?? null),
			'advies1Datum' => self::isoDate(value: $advice['voorlopigAdviesDate'] ?? null),
			'advies2' => (self::LEVELS[(string)($advice['definitiefAdviesLevel'] ?? '')] ?? null),
			'advies2Datum' => self::isoDate(value: $advice['definitiefAdviesDate'] ?? null),
		];

		// Advies2 goes as a pair or not at all (DUO's combined field).
		if ($record['advies2'] === null || $record['advies2Datum'] === null) {
			$record['advies2'] = null;
			$record['advies2Datum'] = null;
		}

		return $record;
	}//end compose()

	/**
	 * The advice's Vestiging, or the tenant's only one.
	 *
	 * @param array<string, mixed> $advice   The SchoolAdvies row.
	 * @param string               $tenantId The job's tenant.
	 *
	 * @return array<string, mixed> The Vestiging row, empty when unknown.
	 */
	private function vestigingOf(array $advice, string $tenantId): array {
		$vestigingId = (string)($advice['vestigingId'] ?? '');
		if ($vestigingId !== '') {
			return $this->row(schema: self::VESTIGING_SCHEMA, uuid: $vestigingId, tenantId: $tenantId);
		}

		$filters = ['register' => self::LEARNIQ_REGISTER, 'schema' => self::VESTIGING_SCHEMA];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		try {
			$rows = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => 2], _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			unset($exception);
			return [];
		}

		if (count($rows) !== 1) {
			return [];
		}

		return self::toArray(row: $rows[0]);
	}//end vestigingOf()

	/**
	 * One learniq row by uuid, checked against the tenant.
	 *
	 * @param string $schema   The schema slug.
	 * @param string $uuid     The row's uuid.
	 * @param string $tenantId The job's tenant; empty skips the check.
	 *
	 * @return array<string, mixed> The row, empty when absent, unreadable or another tenant's.
	 */
	private function row(string $schema, string $uuid, string $tenantId): array {
		if ($uuid === '') {
			return [];
		}

		try {
			$entity = $this->objectService->find(
				id: $uuid,
				register: self::LEARNIQ_REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			unset($exception);
			return [];
		}

		if ($entity === null) {
			return [];
		}

		$row = self::toArray(row: $entity);
		if ($tenantId !== '' && isset($row['tenant_id']) === true && (string)$row['tenant_id'] !== $tenantId) {
			return [];
		}

		return $row;
	}//end row()

	/**
	 * DUO's adviesvolgnummer: letters and digits, at most 20, unique per advice.
	 *
	 * @param string $uuid The SchoolAdvies uuid.
	 *
	 * @return string|null The number, or null without a uuid.
	 */
	private static function sequenceNumber(string $uuid): ?string {
		$plain = preg_replace('/[^0-9A-Za-z]/', '', $uuid);
		if ($plain === null || $plain === '') {
			return null;
		}

		return substr($plain, 0, 20);
	}//end sequenceNumber()

	/**
	 * DUO's adviesjaar: the calendar year in which the school year ends.
	 *
	 * @param string $academicYear `YYYY-YYYY`.
	 *
	 * @return string|null The end year, or null when the value has another shape.
	 */
	private static function adviceYear(string $academicYear): ?string {
		if (preg_match('/^[0-9]{4}-([0-9]{4})$/', $academicYear, $match) !== 1) {
			return null;
		}

		return $match[1];
	}//end adviceYear()

	/**
	 * A value when it matches DUO's format, else null.
	 *
	 * @param mixed  $value   The value.
	 * @param string $pattern The format.
	 *
	 * @return string|null The value or null.
	 */
	private static function matching(mixed $value, string $pattern): ?string {
		if (is_string($value) === false || preg_match($pattern, $value) !== 1) {
			return null;
		}

		return $value;
	}//end matching()

	/**
	 * A date as `Y-m-d`, or null.
	 *
	 * @param mixed $value The stored date.
	 *
	 * @return string|null The date.
	 */
	private static function isoDate(mixed $value): ?string {
		if (is_string($value) === false || preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})/', $value, $match) !== 1) {
			return null;
		}

		return $match[1];
	}//end isoDate()

	/**
	 * One OpenRegister result as a plain row.
	 *
	 * @param mixed $row An array or an object entity.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return (array)$row->jsonSerialize();
	}//end toArray()
}//end class
