<?php

/**
 * Learniq Attendance Threshold Crossing Guard
 *
 * Lifecycle guard for the AttendanceThreshold schema's `check-threshold`
 * transition (an `active` -> `active` self-loop, mirroring
 * `Session.substitute-teacher`'s established self-loop precedent). Refuses
 * the transition unless the caller-supplied `checkedMetricValue` (a
 * per-learner unexcused-lesuren figure OpenRegister's aggregation DSL cannot
 * compute declaratively on a shared threshold definition — see
 * openspec/changes/attendance-threshold-calculation/design.md) meets or
 * exceeds the threshold's own `limit`, and that a `checkedLearnerId` was
 * supplied at all.
 *
 * This is the "guarded" half of the guarded-manual-transition bridge that
 * lets `AttendanceFlagCreationHandler` create a real AttendanceFlag today,
 * without a caller being able to force a flag by submitting an
 * under-threshold value.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
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
 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-below-the-limit-is-refused
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the AttendanceThreshold `check-threshold` lifecycle transition.
 *
 * Returns true only when `checkedLearnerId` is set and `checkedMetricValue`
 * meets or exceeds `limit`.
 *
 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag
 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-below-the-limit-is-refused
 */
class AttendanceThresholdCrossingGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'The checked learner has not reached the limit of this attendance threshold.';

	/**
	 * Keys the caller sends with the transition that this guard reads; each is a
	 * declared `inputs` entry on the transition (tests/Unit/Register/LifecycleTransitionInputsTest.php).
	 *
	 * @var list<string>
	 */
	public const TRANSITION_INPUTS = ['checkedLearnerId', 'checkedMetricValue'];
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
	 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-below-the-limit-is-refused
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(threshold: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * @param array<string,mixed> $threshold The object at its target state, transition inputs merged in.
	 *
	 * @return bool True if the transition is allowed; false blocks it (HTTP 422).
	 *
	 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-below-the-limit-is-refused
	 */
	private function allows(array $threshold): bool {

		$learnerId = (string)($threshold['checkedLearnerId'] ?? '');
		if ($learnerId === '') {
			$this->logger->info(
				'[AttendanceThresholdCrossingGuard] Blocking check-threshold: checkedLearnerId is required.'
			);
			return false;
		}

		$metricValue = $threshold['checkedMetricValue'] ?? null;
		if (is_numeric($metricValue) === false) {
			$this->logger->info(
				'[AttendanceThresholdCrossingGuard] Blocking check-threshold: checkedMetricValue is required.'
			);
			return false;
		}

		$limit = $threshold['limit'] ?? null;
		if (is_numeric($limit) === false) {
			$this->logger->warning(
				'[AttendanceThresholdCrossingGuard] Blocking check-threshold: threshold has no numeric limit.'
			);
			return false;
		}

		if ((float)$metricValue < (float)$limit) {
			$this->logger->info(
				'[AttendanceThresholdCrossingGuard] Blocking check-threshold for learner {learner}: ' .
				'checkedMetricValue {value} is below limit {limit}.',
				['learner' => $learnerId, 'value' => $metricValue, 'limit' => $limit]
			);
			return false;
		}

		return true;

	}//end allows()
}//end class
