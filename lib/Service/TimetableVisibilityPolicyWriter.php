<?php

/**
 * Learniq TimetableVisibilityPolicyWriter
 *
 * Writes the school's timetable visibility policy from the admin page, so the
 * page never writes the OpenRegister object itself.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Checks and saves a change to the visibility policy.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
 */
class TimetableVisibilityPolicyWriter {

	/**
	 * The values each policy key accepts.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const CHOICES = [
		'learnerSeesGroups' => ['own', 'all'],
		'learnerSeesTeachers' => ['none', 'related', 'all'],
		'learnerSeesRooms' => ['none', 'related', 'all'],
		'instructorSeesGroups' => ['own', 'all'],
		'instructorSeesTeachers' => ['own', 'all'],
		'instructorSeesRooms' => ['own', 'all'],
	];

	/**
	 * Constructor.
	 *
	 * @param TimetableVisibilityService $visibility    The policy as it applies now.
	 * @param ObjectService              $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TimetableVisibilityService $visibility,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Change the school's policy. Keys left out keep their value; an unknown
	 * key or value is refused and nothing is written.
	 *
	 * @param array<string,mixed> $changes The keys to change.
	 *
	 * @return string|null Null when saved, else why it was refused.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	public function save(array $changes): ?string {
		$policy = $this->visibility->policy();
		foreach ($changes as $key => $value) {
			if (isset(self::CHOICES[$key]) === false) {
				return 'The timetable visibility has no setting "' . $key . '".';
			}

			if (is_string($value) === false || in_array($value, self::CHOICES[$key], true) === false) {
				return 'The timetable visibility setting "' . $key . '" cannot be this value.';
			}

			$policy[$key] = $value;
		}

		$this->objectService->saveObject(
			object: $policy,
			register: Application::APP_ID,
			schema: 'timetable-visibility-policy',
			uuid: $this->visibility->policyId(),
			_rbac: false
		);

		return null;
	}//end save()
}//end class
