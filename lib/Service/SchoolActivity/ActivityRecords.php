<?php

/**
 * Learniq School Activity Records
 *
 * Reads and writes the activity records where they live: portaliq's register
 * holds `activityOffer`, `activitySignup`, `activityAttendance` and the
 * guardians' photo consent (`guardianAudienceFixture`), and portaliq's
 * guardian side reads the same rows. Learniq's staff page reaches them through
 * OpenRegister; the staff check is the controller's, so rows are read without
 * RBAC. A read or write that fails answers null, never a guess.
 *
 * @category Service
 * @package  OCA\Learniq\Service\SchoolActivity
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
 * @spec openspec/changes/school-activities-staff-side/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\SchoolActivity;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The activity rows in portaliq's register.
 *
 * @spec openspec/changes/school-activities-staff-side/tasks.md
 */
class ActivityRecords {

	/**
	 * The register that holds the activity records.
	 */
	public const REGISTER = 'portaliq';

	/**
	 * The activities.
	 */
	public const OFFER = 'activityOffer';

	/**
	 * The guardians' sign-ups.
	 */
	public const SIGNUP = 'activitySignup';

	/**
	 * One mark per child and session.
	 */
	public const ATTENDANCE = 'activityAttendance';

	/**
	 * The guardians' children and photo consent.
	 */
	public const CONSENT = 'guardianAudienceFixture';

	/**
	 * Row cap per read.
	 */
	private const READ_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object access.
	 * @param LoggerInterface $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Rows of one schema matching the filters.
	 *
	 * @param string               $schema  One of the schema constants.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when the read failed.
	 *
	 * @spec openspec/changes/school-activities-staff-side/tasks.md
	 */
	public function rows(string $schema, array $filters = []): ?array {
		try {
			$objects = $this->objectService->findAll(
				config: [
					'filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]),
					'limit' => self::READ_LIMIT,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: school activity read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		$rows = [];
		foreach ($objects as $object) {
			$row = $this->toArray(object: $object);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * One activity by its id or uuid.
	 *
	 * @param string $id The activity's id.
	 *
	 * @return array<string, mixed>|null The activity, or null when unknown or unreadable.
	 *
	 * @spec openspec/changes/school-activities-staff-side/tasks.md
	 */
	public function activity(string $id): ?array {
		if ($id === '') {
			return null;
		}

		foreach (($this->rows(schema: self::OFFER) ?? []) as $row) {
			if (in_array($id, self::keys(row: $row), true) === true) {
				return $row;
			}
		}

		return null;
	}//end activity()

	/**
	 * The sign-ups of one activity.
	 *
	 * @param array<string, mixed> $activity The activity.
	 *
	 * @return array<int, array<string, mixed>>|null The sign-ups, or null when the read failed.
	 *
	 * @spec openspec/changes/school-activities-staff-side/tasks.md
	 */
	public function signupsOf(array $activity): ?array {
		$rows = $this->rows(schema: self::SIGNUP);
		if ($rows === null) {
			return null;
		}

		$keys = self::keys(row: $activity);
		return array_values(array_filter($rows, static fn (array $row): bool => in_array((string)($row['activityRef'] ?? ''), $keys, true)));
	}//end signupsOf()

	/**
	 * Write a row, over an existing one when an id is given.
	 *
	 * @param string               $schema One of the schema constants.
	 * @param array<string, mixed> $data   The row.
	 * @param string               $id     The id to write over, or '' for a new row.
	 *
	 * @return array<string, mixed>|null The saved row, or null when the write failed.
	 *
	 * @spec openspec/changes/school-activities-staff-side/tasks.md
	 */
	public function save(string $schema, array $data, string $id = ''): ?array {
		unset($data['@self']);
		$uuid = null;
		if ($id !== '') {
			$uuid = $id;
		}

		try {
			$saved = $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: school activity write failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		return $this->toArray(object: $saved);
	}//end save()

	/**
	 * The id a row is written over with.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or '' when it has none.
	 *
	 * @spec openspec/changes/school-activities-staff-side/tasks.md
	 */
	public static function idOf(array $row): string {
		return (self::keys(row: $row)[0] ?? '');
	}//end idOf()

	/**
	 * Every key a reference to the row may hold: id, uuid, and its @self id, uuid and slug.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/school-activities-staff-side/tasks.md
	 */
	public static function keys(array $row): array {
		$self = $row['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		$keys = [];
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null), ($self['slug'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				$keys[] = (string)$candidate;
			}
		}

		return array_values(array_unique($keys));
	}//end keys()

	/**
	 * An OpenRegister answer as an array row.
	 *
	 * @param mixed $object An entity or an array.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $object): ?array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return null;
		}

		return $object;
	}//end toArray()
}//end class
