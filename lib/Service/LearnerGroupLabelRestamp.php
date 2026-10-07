<?php

/**
 * Learniq LearnerGroupLabelRestamp
 *
 * Writes a pupil's group line (`LearnerProfile.groupLabel`) again after an
 * enrolment of that pupil changed, so the guardian's menu never names the
 * old group. Saves only when the line actually moved.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-derives and saves one learner profile's group line.
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
 */
class LearnerGroupLabelRestamp {

	private const REGISTER = 'learniq';

	private const SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads and writes the profile.
	 * @param IUserManager    $users         Names the group's teacher.
	 * @param LoggerInterface $logger        Reports a profile that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserManager $users,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the current group line on one profile, when it differs.
	 *
	 * @param string $learnerRef The learner profile id.
	 *
	 * @return bool Whether the profile was saved.
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
	 */
	public function restamp(string $learnerRef): bool {
		try {
			$profile = $this->profile(id: $learnerRef);
			if ($profile === null) {
				return false;
			}

			$label = (new LearnerGroupLabel(objectService: $this->objectService, users: $this->users))->derive(profile: $profile);
			if (($profile['groupLabel'] ?? null) === $label) {
				return false;
			}

			// The row as read carries OpenRegister's `@self` block; saving it
			// back would make OpenRegister check the acting user's folder rights.
			$object = array_merge($profile, ['groupLabel' => $label]);
			unset($object['@self'], $object['id']);
			$this->objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $learnerRef,
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[LearnerGroupLabelRestamp] The group line of profile {id} could not be written: {msg}',
				['id' => $learnerRef, 'msg' => $exception->getMessage()]
			);
			return false;
		}//end try
	}//end restamp()

	/**
	 * The learner profile with this id, with its `id`, or null.
	 *
	 * @param string $id The profile id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws Throwable When OpenRegister cannot be read.
	 */
	private function profile(string $id): ?array {
		if ($id === '') {
			return null;
		}

		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA], 'ids' => [$id], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($rows as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = (array)$row->jsonSerialize();
			}

			if (is_array($data) === true && ($data['id'] ?? ($data['uuid'] ?? null)) === $id) {
				return ['id' => $id] + $data;
			}
		}

		return null;
	}//end profile()
}//end class
