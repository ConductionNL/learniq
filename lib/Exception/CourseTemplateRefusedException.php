<?php

/**
 * Learniq Course Template Refused Exception
 *
 * Thrown when a course structure read, Save as template or New course from
 * template is refused, carrying the HTTP status and a
 * machine-readable reason next to the message.
 *
 * @category Exception
 * @package  OCA\Learniq\Exception
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */

declare(strict_types=1);

namespace OCA\Learniq\Exception;

use RuntimeException;

/**
 * A course template request was refused.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */
class CourseTemplateRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int    $status  The HTTP status to answer with.
	 * @param string $reason  Machine-readable reason, such as `template-not-found`.
	 * @param string $message Readable explanation.
	 */
	public function __construct(
		private readonly int $status,
		private readonly string $reason,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * The machine-readable reason.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
