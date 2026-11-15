<?php

/**
 * Learniq Accessibility Feedback Reporter Stamp
 *
 * Decides, on the server, who reported an accessibility barrier and for
 * which tenant.
 *
 * Any authenticated user must be able to report a barrier. The create form
 * used to ask the reporter for their Nextcloud user id and a tenant UUID,
 * which nobody reporting a barrier knows (#268). So on create the reporter is
 * the signed-in user, whatever the client sent, the tenant is the reporter's
 * own (CallerTenantResolver); on update the stored reporter and tenant are
 * kept. A create without a signed-in user is refused. The lifecycle engine
 * starts every report in `submitted`.
 *
 * OpenRegister validates `required` before any listener runs, so
 * `reporterUserId` and `tenant_id` are not in the schema's `required` list;
 * this listener fills them instead (the ConcernReportReporterStamp pattern).
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\CallerTenantResolverInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Stamps the reporter and tenant of an AccessibilityFeedback on create and keeps them on update.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
 */
class AccessibilityFeedbackReporterStamp implements IEventListener {

	private const SCHEMA = 'accessibility-feedback';

	/**
	 * The refusals this listener can give, keyed by reason.
	 */
	private const REFUSALS = [
		'accessibility-feedback-no-session' => 'Sign in to report an accessibility problem.',
		'accessibility-feedback-reporter-missing' => 'This report has lost who filed it and can not be changed.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Entity schema id to slug.
	 * @param CallerTenantResolverInterface   $tenants        The reporter's tenant.
	 * @param IUserSession           $userSession    The signed-in user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly CallerTenantResolverInterface $tenants,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Stamp the reporter on a create, restore it on an update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
	 */
	public function handle(Event $event): void {
		$write = SchemaWrite::of(event: $event, schemaResolver: $this->schemaResolver);
		if ($write === null || $write->slug !== self::SCHEMA) {
			return;
		}

		$this->stampWrite(write: $write);
	}//end handle()

	/**
	 * Stamp one write of a schema this listener owns.
	 *
	 * @param SchemaWrite $write The write and its schema slug.
	 *
	 * @return void
	 */
	private function stampWrite(SchemaWrite $write): void {
		$event = $write->event;

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->keepReporter(event: $event);
			return;
		}

		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($userId === '') {
			$this->refuse(event: $event, reason: 'accessibility-feedback-no-session');
			return;
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				[
					'reporterUserId' => $userId,
					'tenant_id'      => $this->tenants->forUserId(userId: $userId),
				]
			)
		);
	}//end stampWrite()

	/**
	 * Put the stored reporter and tenant back over whatever the update sends.
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 *
	 * @return void
	 */
	private function keepReporter(ObjectUpdatingEvent $event): void {
		$old = ($event->getOldObject()?->getObject() ?? []);
		$reporter = ($old['reporterUserId'] ?? '');
		if (is_string($reporter) === false || $reporter === '') {
			$this->refuse(event: $event, reason: 'accessibility-feedback-reporter-missing');
			return;
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['reporterUserId' => $reporter, 'tenant_id' => ($old['tenant_id'] ?? null)]
			)
		);
	}//end keepReporter()

	/**
	 * Stop the write with a reason the client can show.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The write event.
	 * @param string                                  $reason A key of REFUSALS.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $reason): void {
		$event->setErrors(['reason' => $reason, 'message' => self::REFUSALS[$reason]]);
		$event->stopPropagation();
	}//end refuse()
}//end class
