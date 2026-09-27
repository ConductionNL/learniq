<?php

/**
 * Learniq Submission Owner Stamp
 *
 * Decides, on the server, who a Submission belongs to.
 *
 * A pupil can hand in through portaliq (ConductionNL/portaliq#745). Portaliq
 * writes the Submission without a Nextcloud session, whitelists only
 * `assignmentId` and `attachmentRefs`, and stamps the pupil's LearnerProfile
 * uuid into the scope field `learnerRef`. It cannot send `learnerIds` or
 * `tenant_id`. OpenRegister validates `required` before any listener runs
 * (ObjectService::saveObject() calls validateObjectIfRequired() before
 * MagicMapper dispatches ObjectCreatingEvent), so those two cannot stay in the
 * schema's `required` list and be stamped. This listener fills them for a
 * portal hand-in and then enforces them for every write, staff included.
 *
 * For every other write it derives `learnerRef` from `learnerIds[0]`, the
 * stamp learniq PR 1020 applies to GradeEntry: a client value is never kept,
 * so nobody can point a Submission at another pupil's portal list.
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
 * @spec openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\Portal\LearnerProfileLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps and enforces the learners and tenant of every Submission write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
 */
class SubmissionOwnerStamp implements IEventListener {

	private const SUBMISSION_SCHEMA = 'submission';
	private const LEARNIQ_REGISTER = 'learniq';
	private const ASSIGNMENT_SCHEMA = 'assignment';

	/**
	 * The refusals this listener can give, keyed by reason.
	 */
	private const REFUSALS = [
		'submission-learner-unknown' => 'This hand-in could not be linked to a pupil. Sign in again or ask the school.',
		'submission-assignment-unknown' => 'The assignment for this hand-in could not be found.',
		'submission-tenant-mismatch' => 'This assignment belongs to another school.',
		'submission-lookup-failed' => 'The pupil for this hand-in could not be checked. Try again later.',
		'submission-owner-missing' => 'A submission needs the learners who hand it in and the school it belongs to.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param LearnerProfileLookup $profiles LearnerProfile by uuid, uuid by user.
	 * @param ObjectService $objectService OpenRegister object access (the Assignment).
	 * @param IUserSession $userSession Tells a portal write (no session) from an app write.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LearnerProfileLookup $profiles,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp, then enforce, the owner fields of a Submission create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true || $this->isSubmission(entity: $this->entityOf(event: $event)) === false) {
			return;
		}

		$payload = array_merge(($this->entityOf(event: $event)->getObject() ?? []), $event->getModifiedData());

		$outcome = $this->outcomeFor(event: $event, payload: $payload);
		$reason = ($outcome['refuse'] ?? null);
		if ($reason === null && $this->ownerMissing(data: array_merge($payload, $outcome['stamp'])) === true) {
			$reason = 'submission-owner-missing';
		}

		if ($reason !== null) {
			$this->refuse(event: $event, reason: $reason);
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $outcome['stamp']));
	}//end handle()

	/**
	 * What to stamp on this write, or why to refuse it: the portal stamp for a
	 * portal hand-in, the derived learnerRef for everything else.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param array<string, mixed> $payload The Submission being written.
	 *
	 * @return array{stamp: array<string, mixed>, refuse?: string}
	 */
	private function outcomeFor(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $payload): array {
		if ($event instanceof ObjectCreatingEvent === true && $this->isPortalHandIn(payload: $payload) === true) {
			return $this->portalStamp(payload: $payload);
		}

		return ['stamp' => ['learnerRef' => $this->derivedRef(event: $event, payload: $payload)]];
	}//end outcomeFor()

	/**
	 * Whether this is a portal hand-in: no Nextcloud session, no learners, and
	 * the pupil's profile uuid stamped by portaliq. A signed-in caller never
	 * takes this path.
	 *
	 * @param array<string, mixed> $payload The Submission being written.
	 *
	 * @return bool
	 */
	private function isPortalHandIn(array $payload): bool {
		if ($this->userSession->getUser() !== null || $this->firstLearner(payload: $payload) !== '') {
			return false;
		}

		$learnerRef = ($payload['learnerRef'] ?? '');

		return is_string($learnerRef) === true && $learnerRef !== '';
	}//end isPortalHandIn()

	/**
	 * The stamp for a portal hand-in, or the reason to refuse it.
	 *
	 * @param array<string, mixed> $payload The Submission being created.
	 *
	 * @return array{stamp: array<string, mixed>, refuse?: string}
	 */
	private function portalStamp(array $payload): array {
		try {
			$profile = $this->profiles->byRef(learnerRef: (string)$payload['learnerRef']);
			$assignment = null;
			if ($profile !== null) {
				$assignment = $this->assignment(id: (string)($payload['assignmentId'] ?? ''));
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[SubmissionOwnerStamp] Could not check a portal hand-in: {msg}',
				['msg' => $exception->getMessage()]
			);
			return ['stamp' => [], 'refuse' => 'submission-lookup-failed'];
		}

		if ($profile === null) {
			return ['stamp' => [], 'refuse' => 'submission-learner-unknown'];
		}

		if ($assignment === null) {
			return ['stamp' => [], 'refuse' => 'submission-assignment-unknown'];
		}

		$profileTenant = (string)($profile['tenant_id'] ?? '');
		$tenant = (string)($assignment['tenant_id'] ?? '');
		if ($tenant !== '' && $profileTenant !== '' && $tenant !== $profileTenant) {
			return ['stamp' => [], 'refuse' => 'submission-tenant-mismatch'];
		}

		if ($tenant === '') {
			$tenant = $profileTenant;
		}

		return [
			'stamp' => [
				'learnerIds' => [(string)$profile['ncUserId']],
				'learnerRefs' => [(string)$profile['id']],
				'learnerRef' => (string)$profile['id'],
				'tenant_id' => $tenant,
			],
		];
	}//end portalStamp()

	/**
	 * The learnerRef for a non-portal write: the profile of `learnerIds[0]`, or
	 * null. On an update whose learners did not change, a failed lookup keeps
	 * the stored value; otherwise it fails closed to null.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param array<string, mixed> $payload The Submission being written.
	 *
	 * @return string|null
	 */
	private function derivedRef(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $payload): ?string {
		try {
			return $this->profiles->refForUser(ncUserId: $this->firstLearner(payload: $payload));
		} catch (Throwable $exception) {
			$kept = null;
			if ($event instanceof ObjectUpdatingEvent === true) {
				$kept = $this->storedRef(event: $event, payload: $payload);
			}

			$this->logger->warning(
				'[SubmissionOwnerStamp] Could not resolve the learner profile, keeping {kept}: {msg}',
				['kept' => ($kept ?? 'null'), 'msg' => $exception->getMessage()]
			);
			return $kept;
		}
	}//end derivedRef()

	/**
	 * The learnerRef stored before this update, or null when there is none or
	 * the update changes the learners (the old value then names the wrong pupil).
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 * @param array<string, mixed> $payload The Submission as it will be saved.
	 *
	 * @return string|null
	 */
	private function storedRef(ObjectUpdatingEvent $event, array $payload): ?string {
		$old = $event->getOldObject();
		if ($old === null) {
			return null;
		}

		$oldData = ($old->getObject() ?? []);
		if (($oldData['learnerIds'] ?? null) !== ($payload['learnerIds'] ?? null)) {
			return null;
		}

		$ref = ($oldData['learnerRef'] ?? null);
		if (is_string($ref) === false || $ref === '') {
			return null;
		}

		return $ref;
	}//end storedRef()

	/**
	 * Read the Assignment raw and without RBAC; null when it does not exist.
	 *
	 * @param string $id Assignment uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function assignment(string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $id,
				register: self::LEARNIQ_REGISTER,
				schema: self::ASSIGNMENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end assignment()

	/**
	 * Whether a Submission lacks its learners or its tenant after stamping.
	 *
	 * @param array<string, mixed> $data The Submission as it would be saved.
	 *
	 * @return bool
	 */
	private function ownerMissing(array $data): bool {
		$tenant = ($data['tenant_id'] ?? '');

		return $this->firstLearner(payload: $data) === '' || is_string($tenant) === false || $tenant === '';
	}//end ownerMissing()

	/**
	 * The first learner id, or '' when there is none.
	 *
	 * @param array<string, mixed> $payload The Submission.
	 *
	 * @return string
	 */
	private function firstLearner(array $payload): string {
		$learnerIds = ($payload['learnerIds'] ?? []);
		if (is_array($learnerIds) === false || $learnerIds === []) {
			return '';
		}

		$first = reset($learnerIds);
		if (is_string($first) === false) {
			return '';
		}

		return $first;
	}//end firstLearner()

	/**
	 * Whether the entity is a Submission. Not knowing the schema is not knowing
	 * it is ours, so another app's writes are never touched.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isSubmission(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::SUBMISSION_SCHEMA;
		} catch (Throwable $exception) {
			return false;
		}
	}//end isSubmission()

	/**
	 * The object being written: the new state on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()

	/**
	 * Stop the write with a reason and a message the caller can show.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param string $reason One of the REFUSALS keys.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $reason): void {
		$event->setErrors(['reason' => $reason, 'message' => self::REFUSALS[$reason]]);
		$event->stopPropagation();

		$this->logger->info('[SubmissionOwnerStamp] Refused a submission write: {reason}', ['reason' => $reason]);
	}//end refuse()
}//end class
