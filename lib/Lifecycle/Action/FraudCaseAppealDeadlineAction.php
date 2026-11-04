<?php

/**
 * Learniq FraudCaseAppealDeadlineAction
 *
 * Transition action that stamps `decidedAt` and `appealDeadline` (the school's
 * appeal term, 42 days by default) onto a FraudCase on its decide transition.
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
use OCA\Learniq\Service\Settings\AppealTerm;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;

/**
 * Writes `decidedAt` (server UTC time) and `appealDeadline` (decidedAt plus the
 * school's appeal term, app setting `examBoardAppealDays`, 42 days by default)
 * onto a deciding FraudCase.
 *
 * The stamp used to be written by FraudCaseDecisionGuard into a mutable
 * payload. OpenRegister calls guards by value and its guard interface forbids
 * mutation, so the guard now only checks and this action writes: OpenRegister's
 * LifecycleActionListener merges the returned array into the object it saves
 * (learniq#983). The register's calculation DSL has no date arithmetic, which
 * is why this is PHP and not a declared calculation.
 *
 * @spec openspec/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-the-appeal-deadline-from-the-schools-appeal-term
 */
class FraudCaseAppealDeadlineAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param AppealTerm|null $appealTerm The school's appeal term; without it, 42 days.
	 */
	public function __construct(
		private readonly ?AppealTerm $appealTerm = null,
	) {
	}//end __construct()

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
	 * @spec openspec/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-the-appeal-deadline-from-the-schools-appeal-term
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$objectData['decidedAt'] = $now->format(DateTimeInterface::ATOM);
		$days = ($this->appealTerm?->days() ?? AppealTerm::DEFAULT_DAYS);
		$objectData['appealDeadline'] = $now->modify('+' . $days . ' days')->format(DateTimeInterface::ATOM);

		return $objectData;
	}//end execute()
}//end class
