<?php

/**
 * Learniq check of a row that still lacks its learner profile reference, for a repair step.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Repair plumbing, not a behavioural requirement: it tells a
 *       learnerRef back-fill which rows to stamp.
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair\Support;

/**
 * The learnerId and uuid of a row that still needs a learnerRef. A row that
 * already carries a non-empty `learnerRef`, or lacks a non-empty string
 * `learnerId` or uuid (`id`, else `uuid`), needs nothing.
 *
 * @spec exclude Repair plumbing, not a behavioural requirement.
 */
final class MissingLearnerRef {

	/**
	 * The learnerId and uuid of a row that still needs a learnerRef, or null
	 * when it already has one or lacks either value.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array{0: string, 1: string}|null
	 *
	 * @spec exclude Repair plumbing, not a behavioural requirement.
	 */
	public function inRow(array $row): ?array {
		$existing = ($row['learnerRef'] ?? null);
		if (is_string($existing) === true && $existing !== '') {
			return null;
		}

		$learnerId = ($row['learnerId'] ?? null);
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($learnerId) === false || $learnerId === '' || is_string($uuid) === false || $uuid === '') {
			return null;
		}

		return [$learnerId, $uuid];
	}//end inRow()
}//end class
