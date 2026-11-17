<?php

/**
 * Learniq Leave Request Portal Stamp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A guardian asks leave through portaliq's `requestLeave` action. Portaliq
 * stamps the guardian's own uuid into `requestedByRef` from her session and
 * checks the chosen child against her children before it writes. This
 * listener checks it again on the server: the pupil named in `learnerRef`
 * must exist and, when a guardian asked, must list that guardian in
 * `guardianRefs`. Otherwise the write is refused and nothing is stored. It
 * then stamps the pupil's tenant and the teachers of the pupil's groups, so
 * a group teacher reads the leave of their own pupils.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Kernel\Contracts\PupilGroupTeachersInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a leave request for somebody else's child and stamps the pupil's teachers.
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/portal-contribution/spec.md#requirement-a-guardian-asks-leave-for-her-own-child-and-sees-the-decision
 */
class LeaveRequestPortalStamp implements IEventListener {

	/**
	 * What a refused write says, by reason.
	 *
	 * @var array<string, string>
	 */
	public const REFUSALS = [
		'leave-learner-unknown' => 'Deze leerling is niet bekend.',
		'leave-guardian-unknown' => 'Verlof vragen kan alleen voor uw eigen kind.',
		'leave-lookup-failed' => 'De leerling van deze verlofaanvraag kon niet worden gecontroleerd. Probeer het later opnieuw.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolverInterface     $profiles       LearnerProfile by uuid.
	 * @param PupilGroupTeachersInterface     $groupTeachers  The teachers of a pupil's current groups.
	 * @param LoggerInterface                 $logger         PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LearnerRefResolverInterface $profiles,
		private readonly PupilGroupTeachersInterface $groupTeachers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check and stamp a new leave request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/portal-contribution/spec.md#requirement-a-guardian-asks-leave-for-her-own-child-and-sees-the-decision
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false || $event->isPropagationStopped() === true) {
			return;
		}

		$entity = $event->getObject();
		if ($this->isLeaveRequest(entity: $entity) === false) {
			return;
		}

		$payload     = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$guardianRef = $this->text(value: ($payload['requestedByRef'] ?? null));

		try {
			$child = $this->profiles->byRef(learnerRef: $this->text(value: ($payload['learnerRef'] ?? null)));
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[LeaveRequestPortalStamp] Could not check a leave request: {msg}',
				['msg' => $exception->getMessage()]
			);
			$this->refuse(event: $event, reason: 'leave-lookup-failed');
			return;
		}

		if ($child === null) {
			$this->refuse(event: $event, reason: 'leave-learner-unknown');
			return;
		}

		if ($guardianRef !== '' && in_array($guardianRef, (array)($child['guardianRefs'] ?? []), true) === false) {
			$this->refuse(event: $event, reason: 'leave-guardian-unknown');
			return;
		}

		$stamp = [
			'learnerRef' => (string)$child['id'],
			'teacherIds' => $this->teachersOf(learnerId: $this->text(value: ($child['ncUserId'] ?? null))),
		];
		$tenant = $this->text(value: ($child['tenant_id'] ?? null));
		if ($tenant !== '') {
			$stamp['tenant_id'] = $tenant;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $stamp));
	}//end handle()

	/**
	 * The teachers of the pupil's current groups; none when they cannot be
	 * resolved, which leaves the request to school-wide staff and never
	 * widens who reads it.
	 *
	 * @param string $learnerId Nextcloud user id of the pupil, '' when unknown.
	 *
	 * @return array<int, string>
	 */
	private function teachersOf(string $learnerId): array {
		if ($learnerId === '') {
			return [];
		}

		try {
			return array_values($this->groupTeachers->forLearner(learnerId: $learnerId));
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[LeaveRequestPortalStamp] Could not resolve the group teachers: {msg}',
				['msg' => $exception->getMessage()]
			);
			return [];
		}
	}//end teachersOf()

	/**
	 * Whether the object is a leave request.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return bool
	 */
	private function isLeaveRequest(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === LeaveRequestSchoolDaysStamp::SCHEMA;
		} catch (Throwable $exception) {
			unset($exception);
			return false;
		}
	}//end isLeaveRequest()

	/**
	 * A scalar as a trimmed string, anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false && is_int($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

	/**
	 * Refuse the write with the reason in words.
	 *
	 * @param ObjectCreatingEvent $event  The write.
	 * @param string              $reason One of the REFUSALS keys.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent $event, string $reason): void {
		$event->setErrors(['reason' => $reason, 'message' => self::REFUSALS[$reason]]);
		$event->stopPropagation();
	}//end refuse()
}//end class
