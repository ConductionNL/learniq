<?php

/**
 * Learniq Draft Submit Exception
 *
 * Thrown when DraftSubmitter could not create and submit an object: the save
 * was refused (`invalid`) or the `submit` transition was (`refused`).
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
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */

declare(strict_types=1);

namespace OCA\Learniq\Exception;

use RuntimeException;
use Throwable;

/**
 * A create-and-submit that did not go through.
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */
class DraftSubmitException extends RuntimeException {

	public const INVALID = 'invalid';

	public const REFUSED = 'refused';

	/**
	 * Constructor.
	 *
	 * @param string         $reason   `invalid` or `refused`.
	 * @param Throwable|null $previous What OpenRegister threw.
	 */
	public function __construct(
		private readonly string $reason,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $reason, previous: $previous);
	}//end __construct()

	/**
	 * Why it did not go through: `invalid` or `refused`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
