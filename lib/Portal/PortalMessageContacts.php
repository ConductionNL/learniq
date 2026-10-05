<?php

/**
 * Learniq PortalMessageContacts
 *
 * Who a guardian or a pupil may write to on the portal (portal-message-contacts,
 * portaliq `site-messages-per-record`): the teachers of the pupil's current
 * groups, with the name a person reads and their role in words. Portaliq calls
 * this through the provider for ONE row the resident owns, after reading that
 * row through its own scoped reader, so the id here is never taken on trust
 * from the browser; the row is read again here all the same, and anything
 * that does not resolve answers nobody.
 *
 * A guardian writes about a child (a learner profile, `childContacts`); a
 * pupil writes from one of her own enrolments (`ownContacts`), and only an
 * active enrolment names anyone, so last year's group stays quiet.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/portal-message-contacts/specs/portal-contribution/spec.md#requirement-a-guardian-and-a-pupil-may-write-to-the-teachers-of-the-pupils-current-groups
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The teachers a resident may write to, per learner profile or enrolment.
 *
 * @spec openspec/changes/portal-message-contacts/specs/portal-contribution/spec.md#requirement-a-guardian-and-a-pupil-may-write-to-the-teachers-of-the-pupils-current-groups
 */
class PortalMessageContacts {

	private const REGISTER = 'learniq';

	/**
	 * Cohort lifecycles that no longer put a pupil in a group.
	 */
	private const PAST_COHORT = ['completed', 'archived'];

	/**
	 * The most groups one pupil is read in.
	 */
	private const MAX_GROUPS = 50;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister reads (system context: portaliq already proved the row).
	 * @param IUserManager    $users         Display names of staff.
	 * @param LoggerInterface $logger        Logs a read that failed.
	 * @param IL10N|null      $l10n          The role words in the portal's language.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserManager $users,
		private readonly LoggerInterface $logger,
		private readonly ?IL10N $l10n=null,
	) {
	}//end __construct()

	/**
	 * The teachers of a child's current groups, for a guardian.
	 *
	 * @param string $profileId The child's learner profile id.
	 *
	 * @return array<int, array{staffRef: string, name: string, role: string}>
	 *
	 * @spec openspec/changes/portal-message-contacts/specs/portal-contribution/spec.md#requirement-a-guardian-and-a-pupil-may-write-to-the-teachers-of-the-pupils-current-groups
	 */
	public function childContacts(string $profileId): array {
		$profile = $this->one(schema: 'learner-profile', id: $profileId);
		$userId  = (string)($profile['ncUserId'] ?? '');
		if ($userId === '') {
			return [];
		}

		$cohorts = $this->find(schema: 'cohort', filters: ['learnerIds' => $userId], limit: self::MAX_GROUPS);
		$current = array_filter(
			$cohorts,
			static fn (array $cohort): bool => in_array(($cohort['lifecycle'] ?? null), self::PAST_COHORT, true) === false
				&& in_array($userId, (array)($cohort['learnerIds'] ?? []), true) === true
		);

		return $this->contactsOf(cohorts: array_values($current));
	}//end childContacts()

	/**
	 * The teachers of the group of one of a pupil's own enrolments; nobody
	 * for an enrolment that is not active.
	 *
	 * @param string $enrolmentId The enrolment id.
	 *
	 * @return array<int, array{staffRef: string, name: string, role: string}>
	 *
	 * @spec openspec/changes/portal-message-contacts/specs/portal-contribution/spec.md#requirement-a-guardian-and-a-pupil-may-write-to-the-teachers-of-the-pupils-current-groups
	 */
	public function ownContacts(string $enrolmentId): array {
		$enrolment = $this->one(schema: 'enrolment', id: $enrolmentId);
		if (($enrolment['lifecycle'] ?? null) !== 'active' || (string)($enrolment['cohortId'] ?? '') === '') {
			return [];
		}

		$cohort = $this->one(schema: 'cohort', id: (string)$enrolment['cohortId']);
		if ($cohort === null || in_array(($cohort['lifecycle'] ?? null), self::PAST_COHORT, true) === true) {
			return [];
		}

		return $this->contactsOf(cohorts: [$cohort]);
	}//end ownContacts()

	/**
	 * The teachers of some groups, the group's own primary teacher first,
	 * each once, each with a readable name; a teacher whose account has no
	 * name of its own is left out rather than shown as a user id.
	 *
	 * @param array<int, array<string, mixed>> $cohorts The groups.
	 *
	 * @return array<int, array{staffRef: string, name: string, role: string}>
	 */
	private function contactsOf(array $cohorts): array {
		$ordered = [];
		foreach ($cohorts as $cohort) {
			foreach ((array)($cohort['teacherAssignments'] ?? []) as $assignment) {
				if (is_array($assignment) === true && ($assignment['role'] ?? null) === 'primary') {
					$ordered[] = ($assignment['teacherId'] ?? null);
				}
			}
		}

		foreach ($cohorts as $cohort) {
			$ordered = array_merge($ordered, (array)($cohort['teacherIds'] ?? []));
		}

		$out  = [];
		$seen = [];
		foreach ($ordered as $userId) {
			if (is_string($userId) === false || $userId === '' || isset($seen[$userId]) === true) {
				continue;
			}

			$seen[$userId] = true;
			$name          = $this->nameOf(userId: $userId);
			if ($name === null) {
				continue;
			}

			$out[] = ['staffRef' => $userId, 'name' => $name, 'role' => $this->roleOf(userId: $userId)];
		}

		return $out;
	}//end contactsOf()

	/**
	 * A staff member's display name, or null when the account has none of its
	 * own (Nextcloud then answers the user id) or does not exist.
	 *
	 * @param string $userId The user id.
	 *
	 * @return string|null
	 */
	private function nameOf(string $userId): ?string {
		$name = trim((string)($this->users->getDisplayName($userId) ?? ''));
		if ($name === '' || $name === $userId) {
			return null;
		}

		return $name;
	}//end nameOf()

	/**
	 * The role in words: Mentor for a staff member the school marked as one,
	 * else Teacher.
	 *
	 * @param string $userId The user id.
	 *
	 * @return string
	 */
	private function roleOf(string $userId): string {
		$staff = $this->find(schema: 'staff', filters: ['ncUserId' => $userId], limit: 1);
		$roles = (array)($staff[0]['roles'] ?? []);
		$word  = 'Teacher';
		if (in_array('mentor', $roles, true) === true) {
			$word = 'Mentor';
		}

		return $this->l10n?->t($word) ?? $word;
	}//end roleOf()

	/**
	 * One object by id, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false, _render: false);
		} catch (Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return (array)$object->jsonSerialize();
	}//end one()

	/**
	 * Rows of a learniq schema, as arrays; none when the read fails.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   The most rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function find(string $schema, array $filters, int $limit): array {
		try {
			$objects = $this->objectService->findAll(
				config: [
					'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
					'limit' => $limit,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: portal message contacts read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach ((array)$objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
			} else if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;
	}//end find()
}//end class
