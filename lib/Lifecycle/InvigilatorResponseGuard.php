<?php

/**
 * Learniq Invigilator Response Guard
 *
 * Only the colleague an InvigilatorAssignment asks may confirm or decline it.
 * A planner cannot answer on someone's behalf: a pending request stays pending
 * until the invigilator says yes or no, so nobody assumes a person will be there.
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
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-invigilator-assignment
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Allows confirm and decline for the invigilator named on the request only.
 *
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-invigilator-assignment
 */
class InvigilatorResponseGuard implements LifecycleGuardInterface {

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
	 * Allow the answer when the actor is the invigilator on the request.
	 *
	 * @param array<string, mixed> $object The InvigilatorAssignment.
	 * @param string $action The transition, confirm or decline.
	 * @param string $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-invigilator-assignment
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$invigilator = (string)($object['invigilatorId'] ?? '');
		if ($userId !== '' && $userId === $invigilator) {
			return GuardResult::allow();
		}

		$this->logger->info(
			'[InvigilatorResponseGuard] {actor} may not {action} an invigilation request for someone else.',
			['actor' => $userId, 'action' => $action]
		);

		return GuardResult::deny('Only the colleague who was asked can confirm or decline this invigilation.');
	}//end check()
}//end class
