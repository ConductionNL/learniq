<?php

/**
 * Learniq guardian-or-self submit verdict.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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

namespace OCA\Learniq\Lifecycle;

/**
 * What GuardianSubmitCheck decided: allowed, or which check refused, with
 * the values the owning guard logs for that refusal.
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */
final class GuardianSubmitVerdict {

	/**
	 * The caller may submit.
	 */
	public const ALLOWED = 'allowed';

	/**
	 * The object names no learner.
	 */
	public const NO_LEARNER = 'no-learner';

	/**
	 * The session has no authenticated user.
	 */
	public const NO_CALLER = 'no-caller';

	/**
	 * The learner has no LearnerProfile.
	 */
	public const NO_PROFILE = 'no-profile';

	/**
	 * The caller is neither a linked guardian nor the learner.
	 */
	public const NOT_GUARDIAN = 'not-guardian';

	/**
	 * Constructor.
	 *
	 * @param string              $outcome One of the outcome constants.
	 * @param array<string,mixed> $context The log context for a refusal.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly array $context,
	) {
	}//end __construct()

	/**
	 * Whether the caller may submit.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
	 */
	public function isAllowed(): bool {
		return $this->outcome === self::ALLOWED;
	}//end isAllowed()
}//end class
