<?php

/**
 * Learniq StampTransitionActorAction
 *
 * Transition action that writes the acting user, and optionally the server
 * time, onto the transitioning object. Declared on ExamAccommodation.approve,
 * ExternalTrainingRecord.verify and ExchangeRejection.waive.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle\Action
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\IUserSession;
use RuntimeException;

/**
 * Stamps who performed a transition, never trusting a caller-supplied value.
 *
 * `actionParameters`:
 *   - `actorField` (required): the field that receives the session user's uid.
 *   - `timeField` (optional): the field that receives the server's UTC time in
 *     ATOM format.
 *
 * The stamp used to be written by the transition's guard into a mutable
 * payload. OpenRegister calls guards by value and its guard interface forbids
 * mutation, so the guard now only checks and this action writes: OpenRegister's
 * LifecycleActionListener merges the returned array into the object it saves
 * (learniq#983). The action gets no user id from OpenRegister, so it reads the
 * same session user the guard was handed.
 *
 * @spec openspec/specs/external-training-recording/spec.md#requirement-verification-must-be-gated-and-tamper-resistant
 */
class StampTransitionActorAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The session whose user performed the transition.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Stamp the actor (and the time, when declared) onto the object.
	 *
	 * @param array<string,mixed> $objectData   The object after the lifecycle field moved to its target.
	 * @param array<string,mixed> $previousData The object before the transition (unused).
	 * @param array<string,mixed> $parameters   The declared `actionParameters`: `actorField`, optional `timeField`.
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The object with the stamp applied.
	 *
	 * @throws RuntimeException When no actorField is declared or there is no session user.
	 *
	 * @spec openspec/specs/external-training-recording/spec.md#requirement-verification-must-be-gated-and-tamper-resistant
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$actorField = $parameters['actorField'] ?? null;
		if (is_string($actorField) === false || $actorField === '') {
			throw new RuntimeException(
				sprintf('Lifecycle action "%s" declares no actorField to stamp.', $actionName)
			);
		}

		$actor = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($actor === '') {
			throw new RuntimeException(
				sprintf('Lifecycle action "%s" found no session user to stamp into "%s".', $actionName, $actorField)
			);
		}

		$objectData[$actorField] = $actor;

		$timeField = $parameters['timeField'] ?? null;
		if (is_string($timeField) === true && $timeField !== '') {
			$objectData[$timeField] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
		}

		return $objectData;
	}//end execute()
}//end class
