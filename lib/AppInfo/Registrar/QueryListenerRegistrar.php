<?php

/**
 * Learniq Query Listener Registrar
 *
 * Wires the in-process query events learniq answers for other fleet apps
 * (ADR-041): a thin event with a result slot, so another app on the same
 * server reads learniq data without a loopback HTTP call. Kept apart from the
 * OpenRegister object event registrars because these are learniq's own events.
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
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Event\HourPlanActivitiesQueryEvent;
use OCA\Learniq\Listener\HourPlanActivitiesQueryListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the in-process query listeners.
 *
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */
class QueryListenerRegistrar {
	/**
	 * Register every query listener.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function register(IRegistrationContext $context): void {
		// Teaching activities of a school year (timetabling-multi-year-hour-plan),
		// read by integriq's rostering export.
		$context->registerEventListener(
			event: HourPlanActivitiesQueryEvent::class,
			listener: HourPlanActivitiesQueryListener::class
		);
	}//end register()
}//end class
