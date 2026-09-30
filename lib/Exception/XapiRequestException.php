<?php

/**
 * Learniq xAPI Request Exception
 *
 * Thrown while reading an xAPI document request when the request cannot be
 * served: a missing or malformed parameter (400), an agent that is not the
 * authenticated learner (403), a failed concurrency precondition (409 or 412),
 * or a body that is too large (413). Carries the HTTP status to answer with.
 *
 * @category Exception
 * @package  OCA\Learniq\Exception
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
 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Exception;

use RuntimeException;

/**
 * An xAPI document request that is answered with an error status.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class XapiRequestException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int    $status  The HTTP status to answer with.
	 * @param string $message The explanation for the caller.
	 */
	public function __construct(
		private readonly int $status,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
