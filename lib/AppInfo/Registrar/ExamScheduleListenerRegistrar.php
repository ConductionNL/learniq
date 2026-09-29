<?php

/**
 * Learniq Exam Schedule Listener Registrar
 *
 * Registers the two pre-write checks of the exam schedule: room capacity and
 * clashes on every ExamSitting create and update, and availability on every
 * new InvigilatorAssignment. Both veto with stopPropagation(), so they are
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
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-exam-periods-and-sittings
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\ExamSittingPlacementCheck;
use OCA\Learniq\Listener\InvigilatorAssignmentCheck;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the exam schedule checks.
 */
class ExamScheduleListenerRegistrar {
	/**
	 * Register the exam schedule listeners.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-exam-periods-and-sittings
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(event: ObjectCreatingEvent::class, listener: ExamSittingPlacementCheck::class);
		$context->registerEventListener(event: ObjectUpdatingEvent::class, listener: ExamSittingPlacementCheck::class);
		$context->registerEventListener(event: ObjectCreatingEvent::class, listener: InvigilatorAssignmentCheck::class);
	}//end register()
}//end class
