<?php

/**
 * Learniq LearnerLevelLabel
 *
 * The two lines under a pupil's name in the portal's person block (board
 * vaartveld MijnOverzicht: "4 havo · klas H4b"): the level and the class of
 * the pupil's active enrolment. Portaliq reads the person block's lines
 * from fields of the pupil's own row (residentMenu.person), so both are kept
 * on the learner profile as readable copies (`levelLabel`, `classLabel`),
 * in the school's own language.
 *
 * - vo: "4 havo" (the class's year and the programme's name) and "klas H4b";
 * - mbo: "Mechatronica, leerjaar 2" and "klas MT4-2A";
 * - po and any group without a programme: no level, and the group's name.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-person-block-names-the-pupils-level-and-class
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Derives a pupil's level and class lines from her active enrolment.
 *
 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-person-block-names-the-pupils-level-and-class
 */
class LearnerLevelLabel {

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService     $objectService Reads the programme.
	 * @param LearnerGroupLabel $groups        Finds the active enrolment and its group.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LearnerGroupLabel $groups,
	) {
	}//end __construct()

	/**
	 * The level and class lines of a learner profile; both null for a profile
	 * that is no pupil or has no active enrolment in a named group.
	 *
	 * @param array<string, mixed> $profile The learner profile.
	 *
	 * @return array{levelLabel: string|null, classLabel: string|null}
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-person-block-names-the-pupils-level-and-class
	 */
	public function derive(array $profile): array {
		$none = ['levelLabel' => null, 'classLabel' => null];
		$id = (string)($profile['id'] ?? '');
		if ($id === '' || in_array('learner', (array)($profile['roles'] ?? []), true) === false) {
			return $none;
		}

		$enrolment = $this->groups->activeEnrolment(learnerRef: $id);
		if ($enrolment === null) {
			return $none;
		}

		$cohort = $this->groups->cohort(id: (string)($enrolment['cohortId'] ?? ''));
		$group = trim((string)($cohort['name'] ?? ($enrolment['cohortName'] ?? '')));
		if ($group === '') {
			return $none;
		}

		return self::lines(cohort: $cohort, group: $group, programme: $this->programme(id: (string)($cohort['programmeId'] ?? '')));
	}//end derive()

	/**
	 * The two lines for a group and its programme.
	 *
	 * @param array<string, mixed> $cohort    The group.
	 * @param string               $group     The group's name.
	 * @param array<string, mixed> $programme The group's programme, or [].
	 *
	 * @return array{levelLabel: string|null, classLabel: string|null}
	 *
	 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-person-block-names-the-pupils-level-and-class
	 */
	public static function lines(array $cohort, string $group, array $programme): array {
		$name = trim((string)($programme['name'] ?? ''));
		$year = (int)($cohort['programmeYear'] ?? 0);
		if ($name === '' || $year < 1) {
			return ['levelLabel' => null, 'classLabel' => $group];
		}

		if (($programme['level'] ?? null) === 'vo') {
			return ['levelLabel' => $year . ' ' . mb_strtolower($name), 'classLabel' => 'klas ' . $group];
		}

		return ['levelLabel' => $name . ', leerjaar ' . $year, 'classLabel' => 'klas ' . $group];
	}//end lines()

	/**
	 * The programme with this id, or an empty array.
	 *
	 * @param string $id The programme id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function programme(string $id): array {
		if ($id === '') {
			return [];
		}

		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => 'programme'], 'ids' => [$id], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($rows as $row) {
			$data = $row;
			if (is_array($row) === false) {
				$data = (array)$row->jsonSerialize();
			}

			if (($data['id'] ?? ($data['uuid'] ?? null)) === $id) {
				return $data;
			}
		}

		return [];
	}//end programme()
}//end class
