<?php

/**
 * Learniq Supersede Prior Learning Plan Action
 *
 * Transition action for the LearningPlan schema's `activate` transition. When the
 * activating plan names the version it replaces (`supersedesId`), moves that
 * version to `superseded` through OpenRegister's TransitionEngine, so only one
 * version of a plan is active at a time.
 *
 * The write used to happen inside LearningPlanSignatureGuard, but a guard only
 * authorises (learniq#983). OpenRegister runs this action only after the guard
 * allowed the activation, so a refused signature supersedes nothing. A failed
 * supersede throws, which aborts the activation rather than leaving two active
 * versions (LifecycleActionInterface forbids a silent no-op).
 *
 * Referenced from the LearningPlan schema's
 * x-openregister-lifecycle.transitions.activate.actions in learniq_register.json.
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
 * @spec openspec/specs/learning-plan/spec.md#requirement-append-on-version-with-immutable-prior-versions
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;

/**
 * Supersedes the prior version of an activating LearningPlan.
 *
 * @spec openspec/specs/learning-plan/spec.md#requirement-append-on-version-with-immutable-prior-versions
 */
class SupersedePriorLearningPlanAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param TransitionEngine $transitionEngine OR lifecycle transition engine.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TransitionEngine $transitionEngine,
	) {
	}//end __construct()

	/**
	 * Supersede the version the activating plan replaces; return the plan unchanged.
	 *
	 * @param array<string, mixed> $objectData   The LearningPlan at `active`.
	 * @param array<string, mixed> $previousData The LearningPlan before the transition.
	 * @param array<string, mixed> $parameters   The declared actionParameters (unused).
	 * @param string               $actionName   The declared action name.
	 *
	 * @return array<string, mixed> The plan, unchanged: this action only writes the prior version.
	 *
	 * @spec openspec/specs/learning-plan/spec.md#requirement-append-on-version-with-immutable-prior-versions
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$supersedesId = (string)($objectData['supersedesId'] ?? '');
		if ($supersedesId !== '') {
			$this->transitionEngine->transition(objectId: $supersedesId, action: 'supersede');
		}

		return $objectData;
	}//end execute()
}//end class
