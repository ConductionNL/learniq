<?php

/**
 * Learniq session change request
 *
 * The session a transition would save and the action that saves it, as
 * SessionChangeGuard checks them.
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
 * @spec openspec/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

/**
 * A session at its target state and the transition action applied to it.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
 */
final class SessionChangeRequest {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $object The session at its target state, transition inputs merged in.
	 * @param string              $action The transition action being applied.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $object,
		public readonly string $action,
	) {
	}//end __construct()
}//end class
