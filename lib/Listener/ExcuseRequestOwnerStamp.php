<?php

/**
 * Learniq Excuse Request Owner Stamp
 *
 * Decides, on the server, whose absence an ExcuseRequest reports, who filed it
 * and which school it belongs to.
 *
 * A pupil or a guardian reports an absence through portaliq. Portaliq writes
 * the ExcuseRequest without a Nextcloud session and whitelists only the dates,
 * the reason, its kind and an attachment. For a pupil it stamps the pupil's
 * LearnerProfile uuid into `learnerRef`; for a guardian it stamps the
 * guardian's own uuid into `submittedByRef` and takes the child's
 * `learnerRef` from the body. It cannot send `learnerId`, `submittedBy`,
 * `submittedAuthLevel` or `tenant_id`. OpenRegister validates `required`
 * before any listener runs, so those fields cannot stay in the schema's
 * `required` list and be stamped (the same finding SubmissionOwnerStamp
 * answers for Submission). This listener fills them for a portal report and
 * then enforces them for every write, staff included.
 *
 * For every other write it derives `learnerRef` from `learnerId`: a client
 * value is never kept, so nobody can point an excuse at another pupil's portal
 * list.
 *
 * On every write it also stamps `teacherIds`, the teachers of the pupil's
 * current groups (PupilGroupTeachers). The schema's read rule lets a teacher
 * read only the reports that list them, because an OpenRegister `match` can
 * only compare a field on the object itself with the caller. A client value
 * is never kept, so nobody can add themselves to a report's audience.
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
 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\AbsenceReportDefaults;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\Portal\PortalWriteSubject;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\PupilGroupTeachers;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps and enforces the learner, submitter and tenant of every ExcuseRequest write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 */
class ExcuseRequestOwnerStamp implements IEventListener {

	private const EXCUSE_SCHEMA = 'excuse-request';

	/**
	 * The assurance level a portal report reached at least: the `minTrust` of
	 * the portal action that wrote it (PortalContributionProvider), mapped
	 * onto the schema's eIDAS enum. A pupil's action asks for `low`, a
	 * guardian's for `substantial`.
	 */
	private const PUPIL_PORTAL_LEVEL = 'basic';
	private const GUARDIAN_PORTAL_LEVEL = 'substantial';

	/**
	 * The schema's default level, for a staff write that sends none.
	 */
	private const DEFAULT_LEVEL = 'basic';

	/**
	 * The refusals this listener can give, keyed by reason.
	 */
	private const REFUSALS = [
		'excuse-learner-unknown' => 'This absence report could not be linked to a pupil. Sign in again or ask the school.',
		'excuse-guardian-unknown' => 'You can only report an absence for your own child.',
		'excuse-lookup-failed' => 'The pupil for this absence report could not be checked. Try again later.',
		'excuse-owner-missing' => 'An absence report needs the pupil, who reports it and the school it belongs to.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolver $profiles LearnerProfile by uuid, uuid by user.
	 * @param LoggerInterface $logger PSR logger.
	 * @param PupilGroupTeachers $groupTeachers The teachers of a pupil's current groups.
	 * @param PortalWriteSubject $writers Whether the portal's own subject is writing.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LearnerRefResolver $profiles,
		private readonly LoggerInterface $logger,
		private readonly PupilGroupTeachers $groupTeachers,
		private readonly PortalWriteSubject $writers,
	) {
	}//end __construct()

	/**
	 * Stamp, then enforce, the owner fields of an ExcuseRequest create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true || $this->isExcuse(entity: $this->entityOf(event: $event)) === false) {
			return;
		}

		$payload = array_merge(($this->entityOf(event: $event)->getObject() ?? []), $event->getModifiedData());

		$outcome = $this->outcomeFor(event: $event, payload: $payload);
		$reason = ($outcome['refuse'] ?? null);
		if ($reason === null && $this->ownerMissing(data: array_merge($payload, $outcome['stamp'])) === true) {
			$reason = 'excuse-owner-missing';
		}

		if ($reason !== null) {
			$this->refuse(event: $event, reason: $reason);
			return;
		}

		$stamp = array_merge($outcome['stamp'], (new AbsenceReportDefaults())->fill(payload: $payload));
		$learnerId = $this->text(value: ($stamp['learnerId'] ?? ($payload['learnerId'] ?? null)));
		$stamp['teacherIds'] = $this->teachersFor(event: $event, learnerId: $learnerId);

		$event->setModifiedData(array_merge($event->getModifiedData(), $stamp));
	}//end handle()

	/**
	 * The teachers of the pupil's current groups. On an update whose pupil did
	 * not change, a failed lookup keeps the stored value; otherwise it stamps
	 * nobody, which leaves the report to school-wide staff and never widens
	 * who reads it.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param string $learnerId Nextcloud user id of the pupil, '' when unknown.
	 *
	 * @return array<int, string>
	 */
	private function teachersFor(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $learnerId): array {
		try {
			return $this->groupTeachers->forLearner(learnerId: $learnerId);
		} catch (Throwable $exception) {
			$kept = [];
			if ($event instanceof ObjectUpdatingEvent === true) {
				$kept = $this->storedTeachers(event: $event, learnerId: $learnerId);
			}

			$this->logger->warning(
				'[ExcuseRequestOwnerStamp] Could not resolve the group teachers, keeping {count}: {msg}',
				['count' => count($kept), 'msg' => $exception->getMessage()]
			);
			return $kept;
		}
	}//end teachersFor()

	/**
	 * The teacherIds stored before this update, or none when the update
	 * changes the pupil (the old teachers then teach someone else).
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 * @param string $learnerId The pupil as it will be saved.
	 *
	 * @return array<int, string>
	 */
	private function storedTeachers(ObjectUpdatingEvent $event, string $learnerId): array {
		$old = $event->getOldObject();
		if ($old === null) {
			return [];
		}

		$oldData = ($old->getObject() ?? []);
		if ($this->text(value: ($oldData['learnerId'] ?? null)) !== $learnerId) {
			return [];
		}

		return array_values(array_filter((array)($oldData['teacherIds'] ?? []), static fn ($id): bool => is_string($id) === true && $id !== ''));
	}//end storedTeachers()

	/**
	 * What to stamp on this write, or why to refuse it.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param array<string, mixed> $payload The ExcuseRequest being written.
	 *
	 * @return array{stamp: array<string, mixed>, refuse?: string}
	 */
	private function outcomeFor(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $payload): array {
		if ($event instanceof ObjectCreatingEvent === true && $this->isPortalReport(payload: $payload) === true) {
			return $this->portalStamp(payload: $payload);
		}

		$stamp = ['learnerRef' => $this->derivedRef(event: $event, payload: $payload)];
		if ($this->text(value: ($payload['submittedAuthLevel'] ?? null)) === '') {
			$stamp['submittedAuthLevel'] = self::DEFAULT_LEVEL;
		}

		return ['stamp' => $stamp];
	}//end outcomeFor()

	/**
	 * Whether this is a portal report: no Nextcloud session, no learnerId, and
	 * a `learnerRef` naming the pupil. A signed-in caller never takes this path.
	 *
	 * @param array<string, mixed> $payload The ExcuseRequest being written.
	 *
	 * @return bool
	 */
	private function isPortalReport(array $payload): bool {
		if ($this->text(value: ($payload['learnerId'] ?? null)) !== '') {
			return false;
		}

		$learnerRef = $this->text(value: ($payload['learnerRef'] ?? null));
		if ($learnerRef === '') {
			return false;
		}

		// Who wrote it decides the branch, not whether anybody is signed in:
		// a pupil signs in to her own portal with her school account
		// (PortalWriteSubject).
		$ref = $this->text(value: ($payload['submittedByRef'] ?? null));
		if ($ref === '') {
			$ref = $learnerRef;
		}

		return $this->writers->wroteItThemselves(ref: $ref);
	}//end isPortalReport()

	/**
	 * The stamp for a portal report, or the reason to refuse it.
	 *
	 * A guardian report carries `submittedByRef` (the guardian); the child must
	 * list that guardian in `guardianRefs`. A pupil report carries none, and the
	 * pupil is the submitter.
	 *
	 * @param array<string, mixed> $payload The ExcuseRequest being created.
	 *
	 * @return array{stamp: array<string, mixed>, refuse?: string}
	 */
	private function portalStamp(array $payload): array {
		$guardianRef = $this->text(value: ($payload['submittedByRef'] ?? null));

		try {
			$child = $this->profiles->byRef(learnerRef: $this->text(value: $payload['learnerRef']));
			$guardian = null;
			if ($child !== null && $guardianRef !== '') {
				$guardian = $this->profiles->byRef(learnerRef: $guardianRef);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExcuseRequestOwnerStamp] Could not check a portal absence report: {msg}',
				['msg' => $exception->getMessage()]
			);
			return ['stamp' => [], 'refuse' => 'excuse-lookup-failed'];
		}

		if ($child === null) {
			return ['stamp' => [], 'refuse' => 'excuse-learner-unknown'];
		}

		$stamp = [
			'learnerId' => (string)$child['ncUserId'],
			'learnerRef' => (string)$child['id'],
			'tenant_id' => $this->text(value: ($child['tenant_id'] ?? null)),
		];

		if ($guardianRef === '') {
			$stamp['submittedBy'] = (string)$child['ncUserId'];
			$stamp['submittedByRef'] = (string)$child['id'];
			$stamp['submittedAuthLevel'] = self::PUPIL_PORTAL_LEVEL;
			return ['stamp' => $stamp];
		}

		if (in_array($guardianRef, (array)($child['guardianRefs'] ?? []), true) === false) {
			return ['stamp' => [], 'refuse' => 'excuse-guardian-unknown'];
		}

		// A guardian without a Nextcloud account has no user id; the report
		// then names them through submittedByRef alone.
		$stamp['submittedBy'] = null;
		if ($guardian !== null) {
			$stamp['submittedBy'] = (string)$guardian['ncUserId'];
		}

		$stamp['submittedAuthLevel'] = self::GUARDIAN_PORTAL_LEVEL;

		return ['stamp' => $stamp];
	}//end portalStamp()

	/**
	 * The learnerRef for a non-portal write: the profile of `learnerId`, or
	 * null. On an update whose learner did not change, a failed lookup keeps
	 * the stored value; otherwise it fails closed to null.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param array<string, mixed> $payload The ExcuseRequest being written.
	 *
	 * @return string|null
	 */
	private function derivedRef(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $payload): ?string {
		try {
			return $this->profiles->resolveAcrossTenants(learnerId: $this->text(value: ($payload['learnerId'] ?? null)));
		} catch (Throwable $exception) {
			$kept = null;
			if ($event instanceof ObjectUpdatingEvent === true) {
				$kept = $this->storedRef(event: $event, payload: $payload);
			}

			$this->logger->warning(
				'[ExcuseRequestOwnerStamp] Could not resolve the learner profile, keeping {kept}: {msg}',
				['kept' => ($kept ?? 'null'), 'msg' => $exception->getMessage()]
			);
			return $kept;
		}
	}//end derivedRef()

	/**
	 * The learnerRef stored before this update, or null when there is none or
	 * the update changes the learner (the old value then names the wrong pupil).
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 * @param array<string, mixed> $payload The ExcuseRequest as it will be saved.
	 *
	 * @return string|null
	 */
	private function storedRef(ObjectUpdatingEvent $event, array $payload): ?string {
		$old = $event->getOldObject();
		if ($old === null) {
			return null;
		}

		$oldData = ($old->getObject() ?? []);
		if (($oldData['learnerId'] ?? null) !== ($payload['learnerId'] ?? null)) {
			return null;
		}

		$ref = $this->text(value: ($oldData['learnerRef'] ?? null));
		if ($ref === '') {
			return null;
		}

		return $ref;
	}//end storedRef()

	/**
	 * Whether an ExcuseRequest lacks its pupil, its submitter or its tenant
	 * after stamping. The submitter is known by user id or, for a guardian
	 * without an account, by `submittedByRef`.
	 *
	 * @param array<string, mixed> $data The ExcuseRequest as it would be saved.
	 *
	 * @return bool
	 */
	private function ownerMissing(array $data): bool {
		$submitter = $this->text(value: ($data['submittedBy'] ?? null)) . $this->text(value: ($data['submittedByRef'] ?? null));

		return $this->text(value: ($data['learnerId'] ?? null)) === ''
			|| $submitter === ''
			|| $this->text(value: ($data['tenant_id'] ?? null)) === '';
	}//end ownerMissing()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()

	/**
	 * Whether the entity is an ExcuseRequest. Not knowing the schema is not
	 * knowing it is ours, so another app's writes are never touched.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isExcuse(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::EXCUSE_SCHEMA;
		} catch (Throwable $exception) {
			return false;
		}
	}//end isExcuse()

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

		$this->logger->info('[ExcuseRequestOwnerStamp] Refused an absence report write: {reason}', ['reason' => $reason]);
	}//end refuse()
}//end class
