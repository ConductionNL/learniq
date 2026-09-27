<?php

/**
 * Learniq Regulation Audience Resolver
 *
 * Decides whether a Regulation covers a LearnerProfile, honouring the
 * regulation's `audienceScope` (learniq#951):
 *
 * - `all-employees` (or no scope): every active profile.
 * - `department`: profiles whose `department` equals, or sits under, one of
 *   the regulation's `audienceDepartments`. Departments are paths written from
 *   the top down with '/' between levels, so 'Operations' covers
 *   'Operations/Infra/Team A'.
 * - `role-specific` and `board`: profiles holding at least one of the
 *   regulation's `audienceRoles`.
 *
 * A scope whose list is empty covers nobody: a regulation scoped to "a
 * department" without naming one must not silently apply to everyone.
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
 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Pure audience-scope predicate plus the department path helpers the roll-up shares.
 */
class RegulationAudienceResolver {

	public const DEPARTMENT_SEPARATOR = '/';

	/**
	 * Whether the regulation covers the profile.
	 *
	 * @param array<string,mixed> $regulation The Regulation object.
	 * @param array<string,mixed> $profile    The LearnerProfile object.
	 *
	 * @return bool
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 */
	public function covers(array $regulation, array $profile): bool {
		if (($profile['lifecycle'] ?? 'active') !== 'active') {
			return false;
		}

		$scope = (string)($regulation['audienceScope'] ?? 'all-employees');

		return match ($scope) {
			'department' => $this->inAnyDepartment(
				department: (string)($profile['department'] ?? ''),
				departments: $this->stringList(value: $regulation['audienceDepartments'] ?? [])
			),
			'role-specific', 'board' => array_intersect(
				$this->stringList(value: $profile['roles'] ?? []),
				$this->stringList(value: $regulation['audienceRoles'] ?? [])
			) !== [],
			default => true,
		};
	}//end covers()

	/**
	 * Every level of a department path, top first:
	 * 'Operations/Infra/Team A' gives 'Operations', 'Operations/Infra',
	 * 'Operations/Infra/Team A'. An empty department gives [''].
	 *
	 * @param string $department The department path.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	public function departmentLevels(string $department): array {
		$parts = array_values(
			array_filter(
				array_map('trim', explode(self::DEPARTMENT_SEPARATOR, $department)),
				static fn (string $part): bool => $part !== ''
			)
		);
		if ($parts === []) {
			return [''];
		}

		$levels = [];
		$total  = count($parts);
		for ($i = 1; $i <= $total; $i++) {
			$levels[] = implode(self::DEPARTMENT_SEPARATOR, array_slice($parts, 0, $i));
		}

		return $levels;
	}//end departmentLevels()

	/**
	 * Whether a department equals or sits under any of the listed departments.
	 *
	 * @param string            $department  The profile's department path.
	 * @param array<int,string> $departments The regulation's departments.
	 *
	 * @return bool
	 */
	private function inAnyDepartment(string $department, array $departments): bool {
		$levels = $this->departmentLevels(department: $department);
		if ($levels === ['']) {
			return false;
		}

		foreach ($departments as $scoped) {
			$scopedLevels = $this->departmentLevels(department: $scoped);
			$scopedPath = $scopedLevels[count($scopedLevels) - 1];
			if ($scopedPath !== '' && in_array($scopedPath, $levels, true) === true) {
				return true;
			}
		}

		return false;
	}//end inAnyDepartment()

	/**
	 * Normalise a list value to non-empty strings.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<int,string>
	 */
	private function stringList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', array_filter($value, 'is_scalar')), static fn (string $v): bool => $v !== ''));
	}//end stringList()
}//end class
