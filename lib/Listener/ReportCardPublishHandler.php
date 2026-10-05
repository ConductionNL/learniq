<?php

/**
 * Learniq Report Card Publish Handler
 *
 * IEventListener for ObjectTransitionedEvent (register=learniq,
 * schema=report-card, to=published-to-parents). Resolves the learner's
 * `LearnerProfile.parentIds[]` and creates one `ReportCardParentNotification`
 * per parent, stamping `visibleFrom = now()` and
 * `idempotencyKey = "{reportCardId}-parent-{recipient}"`.
 *
 * Mirrors `GradeRollupHandler::fanOutParentNotifications()`'s reasoning and
 * shape exactly: OR's declarative `x-openregister-notifications` addresses a
 * single field (`learnerId`, already covered by ReportCard's own declared
 * `reportCardPublished` transition-trigger rule), not a related array
 * (`LearnerProfile.parentIds[]`) — a PHP fan-out bridge is required.
 *
 * ADR-031 legitimate exception: "Lifecycle handler — event-to-object-write
 * bridge that cannot be expressed as a schema declaration."
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
 * @spec openspec/specs/report-card/spec.md#requirement-publication-fans-out-a-learner-parent-notification-mirroring-gradenotifications-reason
 * @spec openspec/specs/report-card/spec.md#scenario-publishing-notifies-the-learner-directly-and-fans-out-to-each-parent
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ReportSubjectGradeRows;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Fans out a ReportCardParentNotification per parent when a ReportCard
 * transitions to `published-to-parents`.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/report-card/spec.md#requirement-publication-fans-out-a-learner-parent-notification-mirroring-gradenotifications-reason
 */
class ReportCardPublishHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const REPORT_CARD_SCHEMA = 'report-card';
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';
	private const REPORT_CARD_PARENT_NOTIFICATION_SCHEMA = 'report-card-parent-notification';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param ITimeFactory $timeFactory NC time source (injectable "now" for tests).
	 * @param LoggerInterface $logger PSR logger.
	 * @param ListenerSchemaResolver $schemas Resolves the transition event's register and schema ids to slugs.
	 * @param IFactory $l10nFactory The instance's language, for the readable subject of the notice.
	 * @param ReportSubjectGradeRows $subjectRows Writes the card's per-subject rows for the parent portal.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolver $schemas,
		private readonly IFactory $l10nFactory,
		private readonly ReportSubjectGradeRows $subjectRows,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-publishing-notifies-the-learner-directly-and-fans-out-to-each-parent
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($this->schemas->eventSchema(event: $event) !== self::REPORT_CARD_SCHEMA || $event->getTo() !== 'published-to-parents') {
			return;
		}

		$card = $event->getObject()->jsonSerialize();
		$this->fanOutParentNotifications(reportCard: $card);

		// The parent portal's bars read one row per subject of the latest report
		// (school-portals-use-the-new-blocks). A failure here never stops the notices.
		try {
			$this->subjectRows->replace(card: $card);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'[ReportCardPublishHandler] The subject rows of report card {id} could not be written: {msg}',
				['id' => (string)($card['id'] ?? ''), 'msg' => $exception->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Resolve `LearnerProfile.parentIds[]` for the report card's learner and
	 * create one `ReportCardParentNotification` per parent.
	 *
	 * @param array<string,mixed> $reportCard The published-to-parents ReportCard data array.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-publishing-notifies-the-learner-directly-and-fans-out-to-each-parent
	 */
	private function fanOutParentNotifications(array $reportCard): void {
		$reportCardId = (string)($reportCard['id'] ?? ($reportCard['uuid'] ?? ''));
		$learnerId = (string)($reportCard['learnerId'] ?? '');

		if ($reportCardId === '' || $learnerId === '') {
			$this->logger->warning('[ReportCardPublishHandler] ReportCard missing id/learnerId; aborting parent fan-out.');
			return;
		}

		// LearnerProfile keys the pupil on ncUserId; it has no learnerId, and a
		// filter on an undeclared property matches nothing. Read without RBAC:
		// the publisher may not read LearnerProfile, and only parentIds is used.
		$profiles = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::LEARNER_PROFILE_SCHEMA,
					'ncUserId' => $learnerId,
				],
				'limit' => 1,
			],
			_rbac: false
		);

		if (empty($profiles) === true) {
			return;
		}

		$profile = $this->normalise(row: $profiles[0]);
		$parentIds = $profile['parentIds'] ?? [];

		if (is_array($parentIds) === false || empty($parentIds) === true) {
			return;
		}

		$learnerRef = $reportCard['learnerRef'] ?? null;
		// The readable line a guardian reads in the portal inbox (site-guardian-portal-design T3), in the
		// instance's language: "Het rapport van Vera staat klaar". It names the child, never a grade.
		$subject = $this->l10nFactory->get('learniq', $this->l10nFactory->findGenericLanguage())
			->t('The report of %s is ready', [trim((string)($profile['givenName'] ?? ''))]);
		$tenantId = (string)($reportCard['tenant_id'] ?? '');
		$visibleFrom = $this->timeFactory->getDateTime()->format(\DATE_ATOM);

		$notifiedCount = 0;

		foreach ($parentIds as $parentId) {
			if (empty($parentId) === true) {
				continue;
			}

			$this->objectService->saveObject(
				register: self::LEARNIQ_REGISTER,
				schema: self::REPORT_CARD_PARENT_NOTIFICATION_SCHEMA,
				object: [
					'event' => 'reportCardPublished',
					'recipient' => $parentId,
					'sourceId' => $reportCardId,
					'learnerId' => $learnerId,
					'learnerRef' => $learnerRef,
					'idempotencyKey' => $reportCardId . '-parent-' . $parentId,
					'visibleFrom' => $visibleFrom,
					'subject' => $subject,
					'tenant_id' => $tenantId,
				]
			);
			$notifiedCount++;
		}//end foreach

		$this->logger->info(
			'[ReportCardPublishHandler] ReportCard {id} published — {count} parent notification(s) created.',
			['id' => $reportCardId, 'count' => $notifiedCount]
		);

	}//end fanOutParentNotifications()

	/**
	 * Normalise an ObjectService row to a plain array.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll().
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return $row->jsonSerialize();
	}//end normalise()
}//end class
