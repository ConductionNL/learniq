<?php

/**
 * Learniq LearnerRef Resolver
 *
 * Turns a Nextcloud user id (`learnerId` on a grade, attempt or submission)
 * into the UUID of that learner's LearnerProfile, the `learnerRef` every
 * portal collection scopes its reads on (ADR-046 A4).
 *
 * Two details decide whether this works at all, and both are wrong in the
 * older copies of this lookup:
 *
 * 1. LearnerProfile declares the user id as `ncUserId`, not `learnerId`.
 *    OpenRegister answers a filter on an undeclared property with zero rows,
 *    so a `learnerId` filter never matches.
 * 2. `ObjectService::findAll()` only reads `filters.register` and
 *    `filters.schema`. Passed at the top level of the config they are inert
 *    and the read runs against whatever context an earlier call left behind.
 *
 * When a user has more than one profile (a learner merge leaves the old one
 * with `mergedInto` set), the survivor wins.
 *
 * It also answers the portal's question the other way round: the active
 * profile a `learnerRef` names (byRef()). Portal requests carry no Nextcloud
 * session, so they read across tenants (resolveAcrossTenants(), byRef()). These two
 * reads lived in Portal\LearnerProfileLookup, added twice (#1068, #1096) as a
 * copy of resolve(); every caller now uses this class and that one is gone.
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
 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Resolves a Nextcloud user id to the UUID of its LearnerProfile.
 *
 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 */
class LearnerRefResolver {

	private const LEARNIQ_REGISTER = 'learniq';
	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * How many profiles one user id may have before we stop looking for the
	 * merge survivor. A user with more is a data problem, not a lookup one.
	 */
	private const MAX_PROFILES = 10;

	/**
	 * Lifecycle states a learner can act from. Empty covers rows written
	 * before the profile had a lifecycle.
	 */
	private const ACTIVE_STATES = ['active', ''];

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
	 * The LearnerProfile UUID for a Nextcloud user id, or null when the user
	 * has no profile. Keeps OpenRegister's tenant scoping: for a signed-in
	 * caller.
	 *
	 * Reads without RBAC: the caller may be a team lead who may write a grade
	 * but not read LearnerProfile, and only the UUID leaves this method.
	 * Errors from OpenRegister propagate, so a caller can tell "no profile"
	 * (null) from "could not look" (exception).
	 *
	 * @param string $learnerId Nextcloud user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
	 */
	public function resolve(string $learnerId): ?string {
		if ($learnerId === '') {
			return null;
		}

		$profiles = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::PROFILE_SCHEMA,
					'ncUserId' => $learnerId,
				],
				'limit' => self::MAX_PROFILES,
			],
			_rbac: false
		);

		return $this->survivor(profiles: $profiles);
	}//end resolve()

	/**
	 * The same answer as resolve(), read across tenants: for a caller without
	 * a Nextcloud session, such as a portal request or a repair step.
	 *
	 * @param string $learnerId Nextcloud user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
	 */
	public function resolveAcrossTenants(string $learnerId): ?string {
		if ($learnerId === '') {
			return null;
		}

		$profiles = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::PROFILE_SCHEMA,
					'ncUserId' => $learnerId,
				],
				'limit' => self::MAX_PROFILES,
			],
			_rbac: false,
			_multitenancy: false
		);

		return $this->survivor(profiles: $profiles);
	}//end resolveAcrossTenants()

	/**
	 * The uuid of the profile that is not merged away, else the first one;
	 * null when there is none.
	 *
	 * @param array<int, mixed> $profiles Profiles found for one user id.
	 *
	 * @return string|null
	 */
	private function survivor(array $profiles): ?string {
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
	}//end survivor()

	/**
	 * The active LearnerProfile a `learnerRef` names, or null when it does not
	 * exist, is merged away, is deleted or names no Nextcloud user.
	 *
	 * Reads without RBAC and across tenants: the portal caller has no session.
	 * The returned row always carries its own uuid at `id`. Errors other than
	 * "does not exist" propagate, so a caller can tell "no such learner" from
	 * "could not look".
	 *
	 * @param string $learnerRef LearnerProfile uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
	 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
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
	 * The object UUID of a serialised row: `id`, `uuid`, or `@self.id`.
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
