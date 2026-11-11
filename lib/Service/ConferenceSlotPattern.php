<?php

/**
 * Learniq conference slot pattern
 *
 * The length of one conversation slot and the buffer between two slots, as
 * ConferenceSlotSlicer cuts a free block with them.
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
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * One round's slot length and buffer, in minutes.
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
final class ConferenceSlotPattern {

	/**
	 * Constructor.
	 *
	 * @param int $durationMinutes Length of one slot in minutes.
	 * @param int $bufferMinutes   Gap between consecutive slots in minutes.
	 */
	public function __construct(
		public readonly int $durationMinutes,
		public readonly int $bufferMinutes,
	) {
	}//end __construct()
}//end class
