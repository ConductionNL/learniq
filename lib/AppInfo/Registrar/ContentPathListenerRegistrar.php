<?php

/**
 * Learniq Content Path Listener Registrar
 *
 * Registers the two pre-write checks of adaptive next steps and Preview as
 * learner: a lesson's next step rules stay inside its course (every Lesson
 * create and update), and a completion, result or xAPI statement written in
 * a preview request is refused. Both veto with stopPropagation(), so they are
 * registered directly and not through the shared post-event proxy.
 *
 * @category AppInfo
 * @package  OCA\Learniq\AppInfo\Registrar
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
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#requirement-preview-as-learner
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\LessonNextStepGuard;
use OCA\Learniq\Listener\PreviewWriteGuard;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the next step and preview checks.
 */
class ContentPathListenerRegistrar {
	/**
	 * Register the next step and preview listeners.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-rule-cannot-leave-the-course
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#requirement-preview-as-learner
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(event: ObjectCreatingEvent::class, listener: LessonNextStepGuard::class);
		$context->registerEventListener(event: ObjectUpdatingEvent::class, listener: LessonNextStepGuard::class);
		$context->registerEventListener(event: ObjectCreatingEvent::class, listener: PreviewWriteGuard::class);
	}//end register()
}//end class
