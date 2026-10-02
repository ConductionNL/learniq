<?php

/**
 * Learniq Roll Call Exception
 *
 * A refusal of the roll-call: an HTTP status and a message the page shows.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
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
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use RuntimeException;

/**
 * Why the roll-call refused a read or a save.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
 */
class RollCallException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message A translated message for the page.
	 * @param int    $status  The HTTP status to answer with.
	 *
	 * @return void
	 */
	public function __construct(string $message, private readonly int $status) {
		parent::__construct($message);
	}//end __construct()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
