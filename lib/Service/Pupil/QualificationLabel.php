<?php

/**
 * Learniq QualificationLabel
 *
 * The line a pupil's page header shows for a student in a placement: the
 * qualification of the running placement with its crebo number, as in
 * "Monteur mechatronica (crebo 25290)".
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

/**
 * The qualification line of a pupil's page header.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
 */
class QualificationLabel {

	/**
	 * The line for the running placement among the pupil's placements.
	 *
	 * @param list<array<string, mixed>> $placements The pupil's placements.
	 *
	 * @return string|null "Name (crebo 12345)", the name or the crebo alone, or null without a running placement.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function forPlacements(array $placements): ?string {
		$placement = $this->running(placements: $placements);
		if ($placement === null) {
			return null;
		}

		$name  = trim((string)($placement['qualificationName'] ?? ''));
		$crebo = trim((string)($placement['crebo'] ?? ''));
		$parts = array_values(array_filter([$name, $this->creboPart(crebo: $crebo, named: $name !== '')], static fn (string $part): bool => $part !== ''));
		if ($parts === []) {
			return null;
		}

		return implode(' ', $parts);
	}//end forPlacements()

	/**
	 * The crebo part: "(crebo 12345)" after a name, "crebo 12345" alone, '' without a crebo.
	 *
	 * @param string $crebo The crebo number.
	 * @param bool   $named Whether a name comes before it.
	 *
	 * @return string
	 */
	private function creboPart(string $crebo, bool $named): string {
		if ($crebo === '') {
			return '';
		}

		if ($named === true) {
			return '(crebo ' . $crebo . ')';
		}

		return 'crebo ' . $crebo;
	}//end creboPart()

	/**
	 * The running placement: the first in a running state, the most current state first.
	 *
	 * @param list<array<string, mixed>> $placements The pupil's placements.
	 *
	 * @return array<string, mixed>|null
	 */
	private function running(array $placements): ?array {
		$byState = [];
		foreach ($placements as $placement) {
			$state = (string)($placement['lifecycle'] ?? '');
			$byState[$state] = ($byState[$state] ?? $placement);
		}

		foreach (PupilPage::RUNNING_PLACEMENT as $state) {
			if (isset($byState[$state]) === true) {
				return $byState[$state];
			}
		}

		return null;
	}//end running()
}//end class
