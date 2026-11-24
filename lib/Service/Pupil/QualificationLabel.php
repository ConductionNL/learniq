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
final class QualificationLabel {

	/**
	 * The line for the running placement among the pupil's placements.
	 *
	 * @param list<array<string, mixed>> $placements The pupil's placements.
	 *
	 * @return string|null "Name (crebo 12345)", the name or the crebo alone, or null without a running placement.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public static function of(array $placements): ?string {
		$placement = PupilPage::runningPlacement(placements: $placements);
		if ($placement === null) {
			return null;
		}

		$name  = trim((string)($placement['qualificationName'] ?? ''));
		$crebo = trim((string)($placement['crebo'] ?? ''));
		if ($crebo === '') {
			return ($name === '' ? null : $name);
		}

		$creboLine = 'crebo ' . $crebo;
		if ($name === '') {
			return $creboLine;
		}

		return $name . ' (' . $creboLine . ')';
	}//end of()
}//end class
