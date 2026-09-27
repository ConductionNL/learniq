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
 * @spec openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Resolves a Nextcloud user id to the UUID of its LearnerProfile.
 *
 * @spec openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
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
	 * has no profile.
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
	 * @spec openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
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
	}//end resolve()

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
