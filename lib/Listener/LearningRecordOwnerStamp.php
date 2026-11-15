<?php

/**
 * Learniq Learning Record Owner Stamp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Decides, on the server, whose learning record an export or a share is.
 *
 * An export is signed over the learner profile it names, so a client that
 * could name the learner could export, sign and download a classmate's
 * record. On create the learner is the signed-in user; only a staff member of
 * a group the schemas let create rows may name another learner, acting in a
 * support capacity. The learner profile and tenant are the learner's own,
 * looked up on the server, and the requester or sharer and the request time
 * are the signed-in user and the server's clock, whatever the client sent. A
 * learner without a learner profile has no record and is refused. On an
 * update the stored owner fields are kept.
 *
 * An administrator and a write without a session (the example-set import) are
 * trusted and left alone, as the learner transition guards do.
 *
 * OpenRegister validates `required` before any listener runs, so the stamped
 * fields are not in the schemas' `required` lists.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\CallerTenantResolverInterface;
use OCA\Learniq\Kernel\Contracts\LearningRecordAggregationServiceInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Stamps the owner of a LearningRecordExport or LearningRecordShare on create and keeps it on update.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
 */
class LearningRecordOwnerStamp implements IEventListener {

	private const EXPORT = 'learning-record-export';

	private const SHARE = 'learning-record-share';

	/**
	 * The owner fields each schema stores, beyond learnerId, learnerRef and tenant_id.
	 */
	private const OWNER_FIELDS = [
		self::EXPORT => ['requestedBy', 'requestedAt'],
		self::SHARE  => ['sharedBy'],
	];

	/**
	 * The staff groups that may export or share a learner's record on their behalf.
	 */
	private const SUPPORT_GROUPS = ['instructors', 'hr', 'compliance-officers', 'team-leads'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface           $schemaResolver  Entity schema id to slug.
	 * @param CallerTenantResolverInterface             $tenants         The learner's tenant.
	 * @param LearningRecordAggregationServiceInterface $learningRecords The learner's own profile.
	 * @param IGroupManager                    $groupManager    Whether the caller is an administrator.
	 * @param IUserSession                     $userSession     The signed-in user.
	 * @param ITimeFactory                     $timeFactory     The server's clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly CallerTenantResolverInterface $tenants,
		private readonly LearningRecordAggregationServiceInterface $learningRecords,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Stamp the owner on a create, restore it on an update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
	 */
	public function handle(Event $event): void {
		$write = SchemaWrite::of(event: $event, schemaResolver: $this->schemaResolver);
		if ($write === null || isset(self::OWNER_FIELDS[$write->slug]) === false) {
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
		$slug = $write->slug;

		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($userId === '' || $this->groupManager->isAdmin($userId) === true) {
			return;
		}

		$fields = [...['learnerId', 'learnerRef', 'tenant_id'], ...self::OWNER_FIELDS[$slug]];
		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->keepOwner(event: $event, fields: $fields);
			return;
		}

		$this->stampOwner(event: $event, userId: $userId, fields: $fields);
	}//end stampWrite()

	/**
	 * Put the stored owner fields back over whatever the update sends.
	 *
	 * @param ObjectUpdatingEvent $event  The update event.
	 * @param array<int,string>   $fields The owner fields of this schema.
	 *
	 * @return void
	 */
	private function keepOwner(ObjectUpdatingEvent $event, array $fields): void {
		$old = ($event->getOldObject()?->getObject() ?? []);
		$kept = [];
		foreach ($fields as $field) {
			$kept[$field] = ($old[$field] ?? null);
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $kept));
	}//end keepOwner()

	/**
	 * Stamp the owner fields of a new row, or refuse it when the learner has no record.
	 *
	 * @param ObjectCreatingEvent $event  The create event.
	 * @param string              $userId The signed-in user.
	 * @param array<int,string>   $fields The owner fields of this schema.
	 *
	 * @return void
	 */
	private function stampOwner(ObjectCreatingEvent $event, string $userId, array $fields): void {
		$learnerId = $this->learnerOf(userId: $userId, named: ($event->getObject()->getObject()['learnerId'] ?? null));
		$learnerRef = $this->learningRecords->resolveLearnerRefForUser(ncUserId: $learnerId);
		if ($learnerRef === null) {
			$event->setErrors(['reason' => 'learning-record-no-profile', 'message' => 'You have no learning record to export or share.']);
			$event->stopPropagation();
			return;
		}

		$stamp = [
			'learnerId'   => $learnerId,
			'learnerRef'  => $learnerRef,
			'tenant_id'   => $this->tenants->forUserId(userId: $learnerId),
			'requestedBy' => $userId,
			'requestedAt' => $this->timeFactory->getDateTime()->format('c'),
			'sharedBy'    => $userId,
		];

		$event->setModifiedData(array_merge($event->getModifiedData(), array_intersect_key($stamp, array_flip($fields))));
	}//end stampOwner()

	/**
	 * The learner a new row is for: the one a support group member names, else the caller.
	 *
	 * @param string $userId The signed-in user.
	 * @param mixed  $named  The learnerId the client sent.
	 *
	 * @return string
	 */
	private function learnerOf(string $userId, mixed $named): string {
		if (is_string($named) === false || $named === '' || $named === $userId) {
			return $userId;
		}

		foreach (self::SUPPORT_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return $named;
			}
		}

		return $userId;
	}//end learnerOf()
}//end class
