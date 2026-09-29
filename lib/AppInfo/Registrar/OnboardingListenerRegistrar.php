<?php

/**
 * Learniq Onboarding Listener Registrar
 *
 * Wires the Nextcloud file event learniq listens to for lesson onboarding
 * (office-file-lesson-onboarding): a Word or PowerPoint file created in a
 * teacher's onboarding folder becomes a LessonOnboardingFile row the teacher
 * reviews. Kept in its own registrar because it is the app's only listener on
 * a Nextcloud Files event rather than an OpenRegister object event.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\LessonOnboardingFileListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;

/**
 * Wires the lesson onboarding file listener.
 */
class OnboardingListenerRegistrar {
	/**
	 * Register the NodeCreatedEvent and NodeRenamedEvent listener.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
	 */
	public function register(IRegistrationContext $context): void {
		// The in-process query listeners (ADR-041) live in their own
		// registrar; EventListenerWiring is at its coupling limit.
		(new QueryListenerRegistrar())->register(context: $context);

		// ADR-031 legitimate exception: a Nextcloud Files event, not an object
		// event, so no schema declaration can express it. The listener only
		// records the file; the teacher confirms before anything is read (D17).
		$context->registerEventListener(NodeCreatedEvent::class, LessonOnboardingFileListener::class);
		// A file moved into the folder from elsewhere in the teacher's files.
		$context->registerEventListener(NodeRenamedEvent::class, LessonOnboardingFileListener::class);

		// Optional lesson sign-up rules (timetabling-elective-lesson-signup).
		// Chained here because EventListenerWiring and SchedulingListenerRegistrar
		// are both at phpmd's coupling limit.
		(new ElectiveListenerRegistrar())->register(context: $context);

	}//end register()
}//end class
