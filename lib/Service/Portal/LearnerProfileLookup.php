<?php

/**
 * Learniq Learner Profile Lookup
 *
 * Resolves a portal subject's LearnerProfile, in both directions a portal
 * write needs: from the profile uuid portaliq stamps (`learnerRef`) to the
 * profile row, and from a Nextcloud user id to the profile uuid.
 *
 * Portal requests carry no Nextcloud session, so both reads run without RBAC.
 * Only the row's own fields leave this class; callers decide what to keep.
 *
 * `refForUser()` does what PR 1020's `LearnerRefResolver::resolve()` does
 * (filter on `ncUserId`, nest `register` and `schema` under `filters`, prefer
 * the merge survivor). The two fold into one once 1020 lands.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
 * @spec openspec/specs/portal-contribution/spec.md#REQ-PCON-000
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Reads a LearnerProfile by uuid, or a profile uuid by Nextcloud user id.
 *
 * @spec openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
 * @spec openspec/specs/portal-contribution/spec.md#REQ-PCON-000
 */
class LearnerProfileLookup {

	private const LEARNIQ_REGISTER = 'learniq';
	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Lifecycle states a portal subject can act from. Empty covers rows written
	 * before the profile had a lifecycle.
	 */
	private const ACTIVE_STATES = ['active', ''];

	/**
	 * How many profiles one user id may have before we stop looking for the
	 * merge survivor. A user with more is a data problem, not a lookup one.
	 */
	private const MAX_PROFILES = 10;

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
	 * The active LearnerProfile a `learnerRef` names, or null when it does not
	 * exist, is merged away, is deleted or names no Nextcloud user.
	 *
	 * The returned row always carries its own uuid at `id`. Errors other than
	 * "does not exist" propagate, so a caller can tell "no such learner" from
	 * "could not look".
	 *
	 * @param string $learnerRef LearnerProfile uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
	 * @spec openspec/specs/portal-contribution/spec.md#REQ-PCON-000
	 */
	public function byRef(string $learnerRef): ?array {
		if ($learnerRef === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $learnerRef,
				register: self::LEARNIQ_REGISTER,
				schema: self::PROFILE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		$row = $this->toRow(object: $object);
		if ($this->isActive(row: $row) === false) {
			return null;
		}

		$row['id'] = ($this->uuidOf(row: $row) ?? $learnerRef);

		return $row;
	}//end byRef()

	/**
	 * The LearnerProfile uuid for a Nextcloud user id, preferring a profile that
	 * is not merged away; null when the user has no profile. Errors propagate.
	 *
	 * @param string $ncUserId Nextcloud user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
	 * @spec openspec/specs/portal-contribution/spec.md#REQ-PCON-000
	 */
	public function refForUser(string $ncUserId): ?string {
		if ($ncUserId === '') {
			return null;
		}

		$profiles = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::PROFILE_SCHEMA,
					'ncUserId' => $ncUserId,
				],
				'limit' => self::MAX_PROFILES,
			],
			_rbac: false,
			_multitenancy: false
		);

		$first = null;
		foreach ($profiles as $profile) {
			$row = $this->toRow(object: $profile);
			$uuid = $this->uuidOf(row: $row);
			if ($uuid === null) {
				continue;
			}

			$mergedInto = ($row['mergedInto'] ?? null);
			if ($mergedInto === null || $mergedInto === '') {
				return $uuid;
			}

			$first = ($first ?? $uuid);
		}

		return $first;
	}//end refForUser()

	/**
	 * Whether a profile row can act: active (or pre-lifecycle), not merged
	 * into another profile, and naming a Nextcloud user.
	 *
	 * @param array<string, mixed> $row Serialised profile row.
	 *
	 * @return bool
	 */
	private function isActive(array $row): bool {
		$state = ($row['lifecycle'] ?? '');
		if (is_string($state) === false || in_array($state, self::ACTIVE_STATES, true) === false) {
			return false;
		}

		$mergedInto = ($row['mergedInto'] ?? null);
		if (is_string($mergedInto) === true && $mergedInto !== '') {
			return false;
		}

		$ncUserId = ($row['ncUserId'] ?? '');

		return is_string($ncUserId) === true && $ncUserId !== '';
	}//end isActive()

	/**
	 * Normalise an OpenRegister result row to an array.
	 *
	 * @param mixed $object An ObjectEntity or an already-serialised row.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$row = $object->jsonSerialize();
			if (is_array($row) === true) {
				return $row;
			}
		}

		return [];
	}//end toRow()

	/**
	 * The object uuid of a serialised row: `id`, `uuid`, or `@self.id`.
	 *
	 * @param array<string, mixed> $row Serialised row.
	 *
	 * @return string|null
	 */
	private function uuidOf(array $row): ?string {
		$candidates = [
			($row['id'] ?? null),
			($row['uuid'] ?? null),
			($row['@self']['id'] ?? null),
		];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end uuidOf()
}//end class
