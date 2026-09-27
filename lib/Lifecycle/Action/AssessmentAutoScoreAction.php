<?php

/**
 * Learniq Assessment Auto Score Action
 *
 * Transition action for the AssessmentResult schema's `submit` transition.
 * Writes the server-computed `autoScore` onto every response, overwriting any
 * client-supplied value. The scoring itself lives in AssessmentScoringHandler,
 * which also guards the transition.
 *
 * The scores used to be written by the guard into its transition context, but
 * OpenRegister calls a guard by value and only merges back what an action
 * returns (learniq#983).
 *
 * Referenced from the AssessmentResult schema's
 * x-openregister-lifecycle.transitions.submit.actions in learniq_register.json.
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\Learniq\Lifecycle\AssessmentScoringHandler;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use RuntimeException;

/**
 * Scores an AssessmentResult on submit and returns it for saving.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
class AssessmentAutoScoreAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param AssessmentScoringHandler $scoringHandler The scoring logic.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AssessmentScoringHandler $scoringHandler,
	) {
	}//end __construct()

	/**
	 * Return the AssessmentResult with every response auto-scored.
	 *
	 * @param array<string,mixed> $objectData The AssessmentResult after the lifecycle moved to `submitted`.
	 * @param array<string,mixed> $previousData The AssessmentResult before the transition.
	 * @param array<string,mixed> $parameters The declared actionParameters (unused).
	 * @param string $actionName The declared action name.
	 *
	 * @return array<string,mixed> The AssessmentResult with autoScores applied.
	 *
	 * @throws RuntimeException When the parent Assessment cannot be resolved, so client scores never persist.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$scored = $this->scoringHandler->score(result: $objectData);
		if ($scored === null) {
			throw new RuntimeException('The assessment this attempt belongs to could not be found, so the answers can not be scored.');
		}

		return $scored;
	}//end execute()
}//end class
