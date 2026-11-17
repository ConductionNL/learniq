<?php

/**
 * Learniq Application Round Open Guard
 *
 * Lifecycle guard for the Application `submit` transition: an application is
 * submitted only to an admissions round that is open.
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-an-applicant-submits-an-application-to-an-open-admissions-round
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards the Application `submit` transition.
 *
 * Reads the stored round the application names. Fails closed: an
 * application without a round, or whose round cannot be read, is refused.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-an-applicant-submits-an-application-to-an-open-admissions-round
 */
class ApplicationRoundOpenGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the applicant when the transition is refused.
	 *
	 * @var string
	 */
	public const DENIAL = 'This admissions round is closed, so the application can not be submitted.';

	/**
	 * The round state in which applications are taken.
	 *
	 * @var string
	 */
	private const OPEN = 'open';

	private const LEARNIQ_REGISTER = 'learniq';
	private const ROUND_SCHEMA = 'admissions-round';

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
	 * @param array<string,mixed> $object The application at its target state, transition inputs merged in.
	 * @param string              $action The transition action being applied.
	 * @param string              $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#scenario-the-round-has-closed
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->roundState(object: $object) === self::OPEN) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The lifecycle state of the application's stored round; '' when it cannot be read.
	 *
	 * @param array<string,mixed> $object The application.
	 *
	 * @return string
	 */
	private function roundState(array $object): string {
		$roundId = ($object['admissionsRoundId'] ?? null);
		if (is_string($roundId) === false || $roundId === '') {
			$this->logger->warning('[ApplicationRoundOpenGuard] Application without an admissions round — denying submit.');
			return '';
		}

		try {
			$round = $this->objectService->find(
				id: $roundId,
				options: new ObjectFindOptions(register: self::LEARNIQ_REGISTER, schema: self::ROUND_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$round = null;
		}

		if ($round === null) {
			$this->logger->warning('[ApplicationRoundOpenGuard] Round {id} not found — denying submit.', ['id' => $roundId]);
			return '';
		}

		return (string)((new ObjectRow())->asArray(object: $round)['lifecycle'] ?? '');
	}//end roundState()
}//end class
