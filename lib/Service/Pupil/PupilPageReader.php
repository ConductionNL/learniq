<?php

/**
 * Learniq Pupil Page Reader
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reads what the pupil's page shows from the learniq register: the pupil's
 * profile, a guardian's profile, and the pupil's conference slots, placements,
 * placement visits, attendance flags and learning plans. Who may read a pupil
 * is decided before it is asked (PupilPageService), so it reads without
 * OpenRegister's per-object checks, the way the register's reader does.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads a pupil's records for the pupil's page.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */
class PupilPageReader {

	private const REGISTER = 'learniq';

	/**
	 * Upper bound on one read.
	 *
	 * @var int
	 */
	private const MAX_ROWS = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The pupil's profile, or null when there is none.
	 *
	 * @param string $learnerId The pupil's Nextcloud user id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function profileOf(string $learnerId): ?array {
		foreach ($this->rows(schema: 'learner-profile', filters: ['ncUserId' => $learnerId]) as $row) {
			if ((string)($row['ncUserId'] ?? '') === $learnerId) {
				return $row;
			}
		}

		return null;
	}//end profileOf()

	/**
	 * A profile by its id, or null when it is not there.
	 *
	 * @param string $profileId The profile's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function profileById(string $profileId): ?array {
		if ($profileId === '') {
			return null;
		}

		foreach ($this->rows(schema: 'learner-profile', filters: ['id' => $profileId]) as $row) {
			if ($this->idOf(row: $row) === $profileId) {
				return $row;
			}
		}

		return null;
	}//end profileById()

	/**
	 * Rows of a schema that belong to the pupil (their `learnerId`).
	 *
	 * @param string $schema    The schema slug: conference-slot, bpv-placement, attendance-flag or learning-plan.
	 * @param string $learnerId The pupil's Nextcloud user id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function ofPupil(string $schema, string $learnerId): array {
		return array_values(
			array_filter(
				$this->rows(schema: $schema, filters: ['learnerId' => $learnerId]),
				static fn (array $row): bool => (string)($row['learnerId'] ?? '') === $learnerId
			)
		);
	}//end ofPupil()

	/**
	 * The visits of placements.
	 *
	 * @param list<string> $placementIds The placements' uuids.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function visitsOf(array $placementIds): array {
		$placementIds = array_values(array_filter($placementIds, static fn (string $id): bool => $id !== ''));
		if ($placementIds === []) {
			return [];
		}

		return array_values(
			array_filter(
				$this->rows(schema: 'bpv-visit', filters: ['bpvPlacementId' => $placementIds]),
				static fn (array $row): bool => in_array((string)($row['bpvPlacementId'] ?? ''), $placementIds, true)
			)
		);
	}//end visitsOf()

	/**
	 * A row's id: its `id`, else its `uuid`, else ''.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function idOf(array $row): string {
		$id = ($row['id'] ?? ($row['uuid'] ?? ''));
		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()

	/**
	 * Rows of a learniq schema, as arrays with their id.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Filters; a list is an IN filter.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
				'limit' => self::MAX_ROWS,
			],
			_rbac: false,
			_multitenancy: false
		);

		$result = [];
		foreach (array_values($rows) as $object) {
			$row = self::asArray(object: $object);
			if ($row === []) {
				continue;
			}

			if (isset($row['id']) === false) {
				$row['id'] = $this->idOf(row: $row);
			}

			$result[] = $row;
		}

		return $result;
	}//end rows()

	/**
	 * An array as it is, an entity as its serialisation, anything else as [].
	 *
	 * @param mixed $object An entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private static function asArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end asArray()
}//end class
