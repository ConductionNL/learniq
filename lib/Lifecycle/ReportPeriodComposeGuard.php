<?php

/**
 * Learniq Report Period Compose Guard
 *
 * Lifecycle guard for the ReportPeriod schema's `compose` transition
 * (open -> composed). Blocks composition until the period is `isLocked`
 * (lockDate is set AND has passed @now) — a coordinator/mentor must not
 * compose report cards while ordinary grade-entry publishing for the
 * period is still allowed, per report-card's "Lock date is enforced by a
 * materialised calculation and guards, not an automatic transition"
 * requirement.
 *
 * `isLocked` is a `materialise: true` x-openregister-calculations field —
 * this guard reads it directly off the fetched object the same way every
 * other cross-schema guard in this app reads a sibling field, falling back
 * to computing it manually from `lockDate` only when the materialised value
 * is absent (defensive — materialisation should always have run, but a
 * guard must not silently allow composition of a not-yet-locked period on a
 * missing/stale calculation).
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Referenced from the ReportPeriod schema's
 * x-openregister-lifecycle.transitions.compose.requires in
 * learniq_register.json.
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
 * @spec openspec/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
 * @spec openspec/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the ReportPeriod `compose` (open -> composed) lifecycle transition.
 *
 * Allows the transition only when the period's `isLocked` calculation is
 * `true`. Blocks otherwise.
 *
 * @spec openspec/specs/report-card/spec.md#requirement-lock-date-is-enforced-by-a-materialised-calculation-and-guards-not-an-automatic-transition
 */
class ReportPeriodComposeGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'The report period is not locked yet, so it can not be composed.';
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
	 * @spec openspec/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the
	 * `compose` transition on a ReportPeriod object. Returns true only when
	 * `isLocked` (the materialised lockDate-passed calculation) is `true`.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True when the period is locked; false blocks the transition.
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
	 * @spec openspec/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
	 */
	private function allows(array $object): bool {
		$periodId = $object['id'] ?? ($object['uuid'] ?? '');

		$isLocked = $object['isLocked'] ?? null;

		if (is_bool($isLocked) === false) {
			// Materialised value absent — defensive fallback, computed the same
			// way as the declared x-openregister-calculations expression.
			$isLocked = $this->computeIsLocked(object: $object);
		}

		if ($isLocked === false) {
			$this->logger->info(
				'[ReportPeriodComposeGuard] ReportPeriod {id} is not yet locked — denying compose transition.',
				['id' => $periodId]
			);
			return false;
		}

		return true;
	}//end allows()

	/**
	 * Defensive fallback: compute whether `lockDate` has passed `@now`,
	 * mirroring the declared `isLocked` x-openregister-calculations
	 * expression exactly (`lockDate` set AND `lockDate < now`).
	 *
	 * @param array<string,mixed> $object The ReportPeriod data array.
	 *
	 * @return bool True when lockDate is set and in the past.
	 */
	private function computeIsLocked(array $object): bool {
		$lockDate = $object['lockDate'] ?? null;

		if ($lockDate === null || $lockDate === '') {
			return false;
		}

		$lockTimestamp = strtotime((string)$lockDate);

		if ($lockTimestamp === false) {
			return false;
		}

		return $lockTimestamp < time();
	}//end computeIsLocked()
}//end class
