<?php

/**
 * Learniq External Training Learner Match
 *
 * Finds the learner a row of an uploaded attendance list names, in the
 * caller's tenant only: an email address (the Nextcloud account with that
 * address, then its LearnerProfile), a LearnerProfile uuid, or a personal
 * number. A learner of another tenant reads exactly like an unknown one
 * (compliance-external-training-spreadsheet-upload design D4).
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
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Matches an uploaded row's learner reference to a LearnerProfile of the tenant.
 *
 * @psalm-api
 *
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
class ExternalTrainingLearnerMatch {

	/**
	 * OpenRegister register slug Learniq objects live in.
	 */
	private const REGISTER = 'learniq';

	/**
	 * Schema slug of the learner profile.
	 */
	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object reads.
	 * @param IUserManager $userManager Finds the account behind an email address.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Find the learner a row names, in the caller's tenant only.
	 *
	 * A reference with an `@` is an email address: the Nextcloud account with
	 * that address, then its LearnerProfile. Anything else is a LearnerProfile
	 * uuid or, failing that, a personal number. A learner of another tenant
	 * reads exactly like an unknown one.
	 *
	 * @param string $reference The learner column.
	 * @param string $tenantId The caller's tenant.
	 *
	 * @return array{id:?string,userId:?string,reason:?string} The match, or the reason there is none.
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
	 */
	public function match(string $reference, string $tenantId): array {
		[$profiles, $unknown] = $this->candidates(reference: $reference, tenantId: $tenantId);

		$byUuid = [];
		foreach ($profiles as $profile) {
			$byUuid[self::idOf(row: $profile)] = $profile;
		}

		unset($byUuid['']);
		if (count($byUuid) === 0) {
			return ['id' => null, 'userId' => null, 'reason' => $unknown];
		}

		if (count($byUuid) > 1) {
			return ['id' => null, 'userId' => null, 'reason' => 'More than one learner matches; use the learner reference instead.'];
		}

		$profile = reset($byUuid);
		$userId = null;
		if (is_string($profile['ncUserId'] ?? null) === true && $profile['ncUserId'] !== '') {
			$userId = $profile['ncUserId'];
		}

		return ['id' => self::idOf(row: $profile), 'userId' => $userId, 'reason' => null];
	}//end match()

	/**
	 * The profiles a learner reference could mean, and the reason to give when there are none.
	 *
	 * @param string $reference The learner column.
	 * @param string $tenantId The caller's tenant.
	 *
	 * @return array{0:array<int,array<string,mixed>>,1:string} The candidates and the not-found reason.
	 */
	private function candidates(string $reference, string $tenantId): array {
		if (str_contains($reference, '@') === true) {
			$profiles = [];
			foreach ($this->userManager->getByEmail($reference) as $user) {
				$profiles = array_merge($profiles, $this->profiles(filters: ['ncUserId' => $user->getUID()], tenantId: $tenantId));
			}

			return [$profiles, 'No learner in your organisation has this email address.'];
		}

		$byId = $this->profileById(id: $reference, tenantId: $tenantId);
		if ($byId !== null) {
			return [[$byId], ''];
		}

		return [$this->profiles(filters: ['personalNumber' => $reference], tenantId: $tenantId), 'No learner in your organisation has this reference.'];
	}//end candidates()

	/**
	 * A LearnerProfile by uuid, when it is in the tenant.
	 *
	 * @param string $id The reference, possibly a uuid.
	 * @param string $tenantId The caller's tenant.
	 *
	 * @return array<string,mixed>|null The profile.
	 */
	private function profileById(string $id, string $tenantId): ?array {
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $id,
				register: self::REGISTER,
				schema: self::PROFILE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException) {
			return null;
		}

		$rows = self::rows(objects: [$object]);
		$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['tenant_id'] ?? null) === $tenantId));

		return $rows[0] ?? null;
	}//end profileById()

	/**
	 * LearnerProfiles by a property, in the tenant.
	 *
	 * The tenant and the property are checked on every row as well as in the
	 * query, so a filter the store does not apply cannot widen the match.
	 *
	 * @param array<string,string> $filters The property to match.
	 * @param string $tenantId The caller's tenant.
	 *
	 * @return array<int,array<string,mixed>> The profiles.
	 */
	private function profiles(array $filters, string $tenantId): array {
		$rows = self::rows(
			objects: $this->objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						['tenant_id' => $tenantId, 'register' => self::REGISTER, 'schema' => self::PROFILE_SCHEMA]
					),
				],
				_rbac: false,
				_multitenancy: false
			)
		);

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters, $tenantId): bool {
					foreach ($filters as $key => $value) {
						if ((string)($row[$key] ?? '') !== $value) {
							return false;
						}
					}

					return ($row['tenant_id'] ?? null) === $tenantId;
				}
			)
		);
	}//end profiles()

	/**
	 * The uuid of a row, whichever key carries it.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private static function idOf(array $row): string {
		$id = $row['id'] ?? $row['uuid'] ?? null;
		if ($id === null && is_array($row['@self'] ?? null) === true) {
			$id = $row['@self']['id'] ?? null;
		}

		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()

	/**
	 * OpenRegister answers with ObjectEntity instances; read them as arrays.
	 *
	 * @param array<int,mixed> $objects The objects.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private static function rows(array $objects): array {
		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
				continue;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;
	}//end rows()
}//end class
