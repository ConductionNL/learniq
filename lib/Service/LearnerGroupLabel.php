<?php

/**
 * Learniq LearnerGroupLabel
 *
 * The line under a child's name in the guardian's menu and on the child's
 * card: "Groep 7 · Meester Daan", the group of the pupil's active enrolment
 * and the display name of that group's first teacher. Portaliq reads a
 * menu entry's subline from the record's own fields (`records.subtitleFields`)
 * and nothing else, so the line is kept on the learner profile as a readable
 * copy (`groupLabel`), the same way `Enrolment.cohortName` is.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;

/**
 * Derives a pupil's group line from the active enrolment and its group.
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
 */
class LearnerGroupLabel {

	private const REGISTER = 'learniq';

	/**
	 * The separator portaliq itself puts between subline fields.
	 */
	public const SEPARATOR = ' · ';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the enrolments and the group.
	 * @param IUserManager  $users         Names the group's teacher.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * The group line of a learner profile, or null.
	 *
	 * Null for a profile that is not a pupil, has no id yet, or has no
	 * active enrolment in a named group. The teacher part is left out when
	 * the teacher has no display name of their own (it would read as a user id).
	 *
	 * @param array<string, mixed> $profile The learner profile as it will be stored, with its `id`.
	 *
	 * @return string|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
	 */
	public function derive(array $profile): ?string {
		$id = (string)($profile['id'] ?? '');
		if ($id === '' || in_array('learner', (array)($profile['roles'] ?? []), true) === false) {
			return null;
		}

		$enrolment = $this->activeEnrolment(learnerRef: $id);
		if ($enrolment === null) {
			return null;
		}

		$cohort = $this->cohort(id: (string)($enrolment['cohortId'] ?? ''));
		$group  = trim((string)($cohort['name'] ?? ($enrolment['cohortName'] ?? '')));
		if ($group === '') {
			return null;
		}

		$teacher = $this->teacherName(uid: (string)(((array)($cohort['teacherIds'] ?? []))[0] ?? ''));
		if ($teacher === null) {
			return $group;
		}

		return $group . self::SEPARATOR . $teacher;
	}//end derive()

	/**
	 * The newest active enrolment of a pupil.
	 *
	 * @param string $learnerRef The learner profile id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function activeEnrolment(string $learnerRef): ?array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register'   => self::REGISTER,
					'schema'     => 'enrolment',
					'learnerRef' => $learnerRef,
				],
				'limit'   => 50,
			],
			_rbac: false,
			_multitenancy: false
		);

		$best = null;
		foreach ($rows as $row) {
			$data = self::toRow(object: $row);
			if (($data['learnerRef'] ?? null) !== $learnerRef || ($data['lifecycle'] ?? null) !== 'active') {
				continue;
			}

			if ($best === null || (string)($data['inschrijvingDate'] ?? '') > (string)($best['inschrijvingDate'] ?? '')) {
				$best = $data;
			}
		}

		return $best;
	}//end activeEnrolment()

	/**
	 * The group with this id, or an empty array.
	 *
	 * @param string $id The cohort id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function cohort(string $id): array {
		if ($id === '') {
			return [];
		}

		$rows = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => 'cohort'],
				'ids'     => [$id],
				'limit'   => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($rows as $row) {
			$data = self::toRow(object: $row);
			if (($data['id'] ?? ($data['uuid'] ?? null)) === $id) {
				return $data;
			}
		}

		return [];
	}//end cohort()

	/**
	 * A teacher's own display name, or null when they have none.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string|null
	 */
	private function teacherName(string $uid): ?string {
		if ($uid === '') {
			return null;
		}

		$name = trim((string)$this->users->getDisplayName($uid));
		if ($name === '' || $name === $uid) {
			return null;
		}

		return $name;
	}//end teacherName()

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $object An array or a serialisable entity.
	 *
	 * @return array<string, mixed>
	 */
	private static function toRow(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end toRow()
}//end class
