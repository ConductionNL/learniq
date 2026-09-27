<?php

/**
 * Learniq FraudCaseAppealDeadlineAction
 *
 * Transition action that stamps `decidedAt` and the 42-day `appealDeadline`
 * onto a FraudCase on its decide transition.
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

/**
 * Writes `decidedAt` (server UTC time) and `appealDeadline` (decidedAt plus the
 * 42-day CBE appeal window) onto a deciding FraudCase.
 *
 * The stamp used to be written by FraudCaseDecisionGuard into a mutable
 * payload. OpenRegister calls guards by value and its guard interface forbids
 * mutation, so the guard now only checks and this action writes: OpenRegister's
 * LifecycleActionListener merges the returned array into the object it saves
 * (learniq#983). The register's calculation DSL has no date arithmetic, which
 * is why this is PHP and not a declared calculation.
 *
 * @spec openspec/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-a-42-day-appeal-deadline
 */
class FraudCaseAppealDeadlineAction implements LifecycleActionInterface {

	/**
	 * Days between decidedAt and the stamped appealDeadline (the CBE 6-week window).
	 */
	private const APPEAL_WINDOW_DAYS = 42;

	/**
	 * Stamp decidedAt and appealDeadline onto the object.
	 *
	 * @param array<string,mixed> $objectData   The FraudCase after the lifecycle field moved to `decided`.
	 * @param array<string,mixed> $previousData The FraudCase before the transition (unused).
	 * @param array<string,mixed> $parameters   The declared `actionParameters` (unused).
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The FraudCase with decidedAt and appealDeadline set.
	 *
	 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-a-42-day-appeal-deadline
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$objectData['decidedAt'] = $now->format(DateTimeInterface::ATOM);
		$objectData['appealDeadline'] = $now->modify('+' . self::APPEAL_WINDOW_DAYS . ' days')->format(DateTimeInterface::ATOM);

		return $objectData;
	}//end execute()
}//end class
