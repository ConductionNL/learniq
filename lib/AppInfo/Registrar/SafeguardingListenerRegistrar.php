<?php

/**
 * Learniq Safeguarding Listener Registrar
 *
 * Chained from OnboardingListenerRegistrar, because EventListenerWiring and
 * the other registrars are at phpmd's coupling limit. This one wires the confidential concern report
 * (support-confidential-concern-report): the server decides who filed a
 * report and keeps it on every update, because the reporter is who may read it.
 * It also wires the regulation exemption request (compliance-exemption-record):
 * the server decides who asked, because the requester may not grant it, and
 * scopes a line manager's request to their direct reports.
 *
 * @category AppInfo
 * @package  OCA\Learniq\AppInfo\Registrar
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
 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-the-server-decides-who-filed-a-report
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\ConcernReportReporterStamp;
use OCA\Learniq\Listener\RegulationExemptionRequestStamp;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the concern report reporter stamp.
 *
 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-the-server-decides-who-filed-a-report
 */
class SafeguardingListenerRegistrar {
	/**
	 * Register the reporter stamp on create and update.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-the-server-decides-who-filed-a-report
	 */
	public function register(IRegistrationContext $context): void {
		// A pre-write rule, registered directly like the integrity owner
		// stamps: ObjectEventSubscription's shared proxy does not consult
		// isPropagationStopped() between subscriptions.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: ConcernReportReporterStamp::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: ConcernReportReporterStamp::class
		);
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: RegulationExemptionRequestStamp::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: RegulationExemptionRequestStamp::class
		);
	}//end register()
}//end class
