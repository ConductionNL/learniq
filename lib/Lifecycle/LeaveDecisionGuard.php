<?php

/**
 * Learniq Leave Decision Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lifecycle guard for the decisions on a LeaveRequest (`allow`, `refuse`,
 * `forward`). Up to ten school days the head of the school decides; above
 * ten the school may only forward the request to the leerplichtambtenaar.
 * A refusal needs a reason, because the guardian reads it.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;

/**
 * Who may decide on a leave request, and which decision the number of school days allows.
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/attendance/spec.md#requirement-a-leave-request-is-asked-in-advance-and-decided-by-the-head-of-the-school
 */
class LeaveDecisionGuard implements LifecycleGuardInterface {

	/**
	 * The group whose members decide on leave: the directeur or teamleider.
	 *
	 * @var string
	 */
	public const DECIDERS = 'administration-managers';

	/**
	 * The most school days the school decides itself; above it the request is forwarded.
	 *
	 * @var integer
	 */
	public const SCHOOL_LIMIT = 10;

	/**
	 * The answer to a refusal without a reason, as the decider reads it.
	 *
	 * @var string
	 */
	public const NO_REASON = 'Afwijzen zonder reden kan niet: de ouders lezen uw reden in het bericht.';

	/**
	 * The answer to a decision by someone outside the deciders.
	 *
	 * @var string
	 */
	public const NOT_A_DECIDER = 'Alleen de directeur of teamleider beslist over verlof.';

	/**
	 * The answer to allowing or refusing more than ten school days.
	 *
	 * @var string
	 */
	public const ABOVE_LIMIT = 'Meer dan tien schooldagen: stuur de aanvraag door naar de leerplichtambtenaar.';

	/**
	 * The answer to forwarding a request the school decides itself.
	 *
	 * @var string
	 */
	public const WITHIN_LIMIT = 'Tot en met tien schooldagen beslist de school zelf.';

	/**
	 * The answer when the number of school days is not known.
	 *
	 * @var string
	 */
	public const DAYS_UNKNOWN = 'Het aantal schooldagen van deze aanvraag is niet bekend.';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Tells whether the acting user is a decider.
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Authorise or deny a decision on a leave request (LifecycleGuardInterface).
	 *
	 * @param array<string, mixed> $object The leave request as it would be saved.
	 * @param string               $action The transition: allow, refuse or forward.
	 * @param string               $userId The acting user.
	 *
	 * @return GuardResult Allowed, or denied with the reason the decider reads.
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/attendance/spec.md#requirement-a-leave-request-is-asked-in-advance-and-decided-by-the-head-of-the-school
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($userId === '' || $this->groupManager->isInGroup($userId, self::DECIDERS) === false) {
			return GuardResult::deny(self::NOT_A_DECIDER);
		}

		$schoolDays = $object['schoolDays'] ?? null;
		if (is_int($schoolDays) === false && (is_string($schoolDays) === false || ctype_digit($schoolDays) === false)) {
			return GuardResult::deny(self::DAYS_UNKNOWN);
		}

		$aboveLimit = ((int)$schoolDays > self::SCHOOL_LIMIT);
		if ($action === 'forward') {
			return $this->forwardResult(aboveLimit: $aboveLimit);
		}

		if ($aboveLimit === true) {
			return GuardResult::deny(self::ABOVE_LIMIT);
		}

		if ($action === 'refuse' && trim((string)($object['decisionNote'] ?? '')) === '') {
			return GuardResult::deny(self::NO_REASON);
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Only a request above the limit is forwarded.
	 *
	 * @param bool $aboveLimit Whether the request spans more school days than the school decides.
	 *
	 * @return GuardResult
	 */
	private function forwardResult(bool $aboveLimit): GuardResult {
		if ($aboveLimit === false) {
			return GuardResult::deny(self::WITHIN_LIMIT);
		}

		return GuardResult::allow();
	}//end forwardResult()
}//end class
