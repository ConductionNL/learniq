<?php

/**
 * Learniq Attendance Flag Report Guard
 *
 * Lifecycle guard for the AttendanceFlag schema's `in-handling → reported`
 * transition. Verifies that the integriq exchange job linked to this flag
 * (target leerplicht) has succeeded before allowing the `reported` state.
 *
 * This ensures integriq has sent the leerplicht report to the municipality
 * before the coordinator can mark the flag as `reported`.
 *
 * If the flag has no dataExchangeJobId (no outbound report was configured),
 * the transition is allowed — the flag was handled manually without a
 * data exchange target.
 *
 * ADR-031: guards referenced via schema `requires:` are resolved by OR's
 * lifecycle engine by class name — no `Application.php` registration needed.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the AttendanceFlag `in-handling → reported` lifecycle transition.
 *
 * When a dataExchangeJobId is set on the flag, verifies the linked integriq
 * exchange job's `exchangeStatus` is `succeeded`. When no job is linked,
 * allows the transition unconditionally (manual report).
 */
class AttendanceFlagReportGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'Integriq has not sent the leerplicht report yet, so this flag can not be marked as reported.';

	private const INTEGRIQ_REGISTER = 'integriq';
	private const INTEGRIQ_JOB_SCHEMA = 'job';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
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
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
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
	 * Allow the `in-handling → reported` transition.
	 *
	 * Returns true when:
	 * - The flag has no dataExchangeJobId (manual report, no data exchange required).
	 * - The linked integriq exchange job's `exchangeStatus` is `succeeded`.
	 *
	 * Returns false when:
	 * - The linked integriq job is not `succeeded` (queued, running, refused, failed, partial).
	 * - The linked integriq job cannot be found (integriq absent too).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True if the report transition is allowed; false otherwise.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
	 * @spec openspec/changes/data-exchange-to-integriq/specs/attendance/spec.md#requirement-the-municipalitys-feedback-on-a-leerplicht-report-is-recorded-on-the-attendance-flag
	 */
	private function allows(array $object): bool {
		$dataExchangeJobId = $object['dataExchangeJobId'] ?? null;

		// No exchange job linked: the flag was handled manually.
		if ($dataExchangeJobId === null || $dataExchangeJobId === '') {
			return true;
		}

		// The job lives in integriq since data-exchange-to-integriq; read its
		// exchangeStatus from integriq's own row.
		try {
			$job = $this->objectService->find(
				id: (string)$dataExchangeJobId,
				register: self::INTEGRIQ_REGISTER,
				schema: self::INTEGRIQ_JOB_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $exception) {
			$job = null;
		}

		if ($job === null) {
			$this->logger->warning(
				'[AttendanceFlagReportGuard] Exchange job {id} not found in integriq — denying report transition.',
				['id' => $dataExchangeJobId]
			);
			return false;
		}

		$jobState = (string)($job->jsonSerialize()['exchangeStatus'] ?? '');
		if ($jobState !== 'succeeded') {
			$this->logger->info(
				'[AttendanceFlagReportGuard] Exchange job {id} is {s}, not succeeded — denying report.',
				['id' => $dataExchangeJobId, 's' => $jobState]
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
