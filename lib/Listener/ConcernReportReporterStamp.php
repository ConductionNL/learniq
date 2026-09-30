<?php

/**
 * Learniq Concern Report Reporter Stamp
 *
 * Decides, on the server, who filed a confidential concern report.
 *
 * A ConcernReport is readable by the confidential counsellors and by the
 * person who filed it, matched on `reporterId`. A client value there would
 * let a learner file a report in a classmate's name, who could then read it,
 * or let an update hand a report to someone else. So on create the reporter
 * is the signed-in user, whatever the client sent, and the tenant is the
 * reporter's LearnerProfile tenant when they have one, and a new report is
 * always received (the counsellor moves it on); on update the stored
 * reporter is kept. A create without a signed-in user is refused.
 *
 * OpenRegister validates `required` before any listener runs, so
 * `reporterId` is not in the schema's `required` list; this listener
 * enforces it instead (the same finding SubmissionOwnerStamp answers).
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
 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-the-server-decides-who-filed-a-report
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the reporter of a ConcernReport on create and keeps it on update.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-the-server-decides-who-filed-a-report
 */
class ConcernReportReporterStamp implements IEventListener {

	private const SCHEMA = 'concern-report';

	/**
	 * The refusals this listener can give, keyed by reason.
	 */
	private const REFUSALS = [
		'concern-no-session' => 'Sign in to report a concern.',
		'concern-reporter-missing' => 'This report has lost who filed it and can not be changed.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolver     $profiles       LearnerProfile by user id and by uuid.
	 * @param IUserSession           $userSession    The signed-in user.
	 * @param LoggerInterface        $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LearnerRefResolver $profiles,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the reporter on a create, restore it on an update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-the-server-decides-who-filed-a-report
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		// An update carries the new state in getNewObject(); only a create has getObject().
		$entity = ($event instanceof ObjectUpdatingEvent) ? $event->getNewObject() : $event->getObject();

		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never touch
			// another app's writes.
			return;
		}

		if ($slug !== self::SCHEMA) {
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->keepReporter(event: $event);
			return;
		}

		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($userId === '') {
			$this->refuse(event: $event, reason: 'concern-no-session');
			return;
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				[
					'reporterId' => $userId,
					'tenant_id'  => $this->tenantOf(userId: $userId),
					'status'     => 'received',
				]
			)
		);
	}//end handle()

	/**
	 * Put the stored reporter and tenant back over whatever the update sends.
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 *
	 * @return void
	 */
	private function keepReporter(ObjectUpdatingEvent $event): void {
		$old = ($event->getOldObject()?->getObject() ?? []);
		$reporter = ($old['reporterId'] ?? '');
		if (is_string($reporter) === false || $reporter === '') {
			$this->refuse(event: $event, reason: 'concern-reporter-missing');
			return;
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['reporterId' => $reporter, 'tenant_id' => ($old['tenant_id'] ?? null)]
			)
		);
	}//end keepReporter()

	/**
	 * The tenant of the reporter's LearnerProfile, or null for someone
	 * without one (a member of staff) or when the lookup fails.
	 *
	 * @param string $userId Nextcloud user id.
	 *
	 * @return string|null
	 */
	private function tenantOf(string $userId): ?string {
		try {
			$profile = $this->profiles->byRef(learnerRef: (string)($this->profiles->resolve(learnerId: $userId) ?? ''));
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ConcernReportReporterStamp] Could not read the reporter\'s learner profile, filing without a tenant: {msg}',
				['msg' => $exception->getMessage()]
			);
			return null;
		}

		$tenant = ($profile['tenant_id'] ?? null);
		if (is_string($tenant) === false || $tenant === '') {
			return null;
		}

		return $tenant;
	}//end tenantOf()

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
