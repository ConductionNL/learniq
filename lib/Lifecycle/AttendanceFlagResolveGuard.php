<?php

/**
 * Learniq Attendance Flag Resolve Guard
 *
 * Lifecycle guard for the AttendanceFlag `resolve` transition. A flag that was
 * reported to the municipality is not closed until the municipality's answer,
 * its MAS route, is recorded on the flag. A flag closed before it was reported
 * (from `open` or `in-handling`) needs no answer.
 *
 * ADR-031: guards referenced via schema `requires:` are resolved by OR's
 * lifecycle engine by class name — no `Application.php` registration needed.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/attendance/spec.md#requirement-a-reported-attendance-flag-is-not-closed-until-the-municipalitys-feedback-is-recorded
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the AttendanceFlag `resolve` lifecycle transition.
 *
 * The guard receives the flag at its target state, so the state it leaves is
 * read from the stored flag. Fails closed: a flag without a recorded answer
 * whose stored state cannot be read is refused.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/attendance/spec.md#requirement-a-reported-attendance-flag-is-not-closed-until-the-municipalitys-feedback-is-recorded
 */
class AttendanceFlagResolveGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	public const DENIAL = "The municipality's answer to this report is not recorded yet, so the flag can not be closed. Record the MAS route first.";

	/**
	 * The state in which the municipality's answer is required.
	 *
	 * @var string
	 */
	private const REPORTED = 'reported';

	private const LEARNIQ_REGISTER = 'learniq';
	private const FLAG_SCHEMA = 'attendance-flag';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OR object access service.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string              $action The transition action being applied.
	 * @param string              $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/attendance/spec.md#requirement-a-reported-attendance-flag-is-not-closed-until-the-municipalitys-feedback-is-recorded
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if (self::hasMasRoute(object: $object) === true || $this->wasReported(object: $object) === false) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * Whether the flag carries the municipality's MAS route.
	 *
	 * @param array<string,mixed> $object The flag.
	 *
	 * @return bool True when `municipalityFeedback.masRoute` is a non-empty string.
	 */
	private static function hasMasRoute(array $object): bool {
		$feedback = ($object['municipalityFeedback'] ?? null);
		if (is_array($feedback) === false) {
			return false;
		}

		$route = ($feedback['masRoute'] ?? null);

		return is_string($route) === true && trim($route) !== '';
	}//end hasMasRoute()

	/**
	 * Whether the stored flag is in `reported`; true when that cannot be read (fail closed).
	 *
	 * @param array<string,mixed> $object The flag at its target state.
	 *
	 * @return bool True when the flag leaves `reported`, or its stored state is unknown.
	 */
	private function wasReported(array $object): bool {
		$flagId = ($object['id'] ?? ($object['uuid'] ?? ($object['@self']['id'] ?? null)));
		if (is_string($flagId) === false || $flagId === '') {
			$this->logger->warning('[AttendanceFlagResolveGuard] Flag without an id — denying resolve without a recorded answer.');
			return true;
		}

		try {
			$stored = $this->objectService->find(
				id: $flagId,
				register: self::LEARNIQ_REGISTER,
				schema: self::FLAG_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $exception) {
			$stored = null;
		}

		if ($stored === null) {
			$this->logger->warning(
				'[AttendanceFlagResolveGuard] Flag {id} not found — denying resolve without a recorded answer.',
				['id' => $flagId]
			);
			return true;
		}

		return (string)($stored->jsonSerialize()['lifecycle'] ?? '') === self::REPORTED;
	}//end wasReported()
}//end class
