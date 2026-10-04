<?php

/**
 * Learniq HourWeekSubmissionStamp
 *
 * Says who entered a week of BPV hours, when, for which student and for which
 * school, from the placement the week names.
 *
 * WHY A LISTENER AND NOT THE FORM. `submittedBy`, `submittedAt`, `learnerRef`
 * and `tenant_id` are identity, so the pupil's form may not send them: a
 * client that could name the student could file hours against somebody else.
 * The placement answers all four, and the placement is the one field the form
 * does send. Portaliq's cross-reference guard has already proved that the
 * placement is hers before this listener sees the write.
 *
 * WHY `tenant_id` LEFT THE SCHEMA'S `required` LIST. OpenRegister validates
 * `required` before any listener runs, so a field cannot be both required and
 * stamped. Same finding as SubmissionOwnerStamp and ExcuseRequestOwnerStamp;
 * this listener therefore enforces it afterwards instead, and a week without
 * a school is refused rather than stored.
 *
 * On an update (the trainer's approval) the week keeps the moment and the
 * person who entered it: only her own decision is being written.
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
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps, then enforces, who a week of hours belongs to.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 */
class HourWeekSubmissionStamp implements IEventListener {

	private const REGISTER = 'learniq';

	private const WEEK_SCHEMA = 'bpv-hour-week';

	private const PLACEMENT_SCHEMA = 'bpv-placement';

	/**
	 * What the pupil reads when a week cannot be attributed.
	 *
	 * @var array<string, string>
	 */
	private const REFUSALS = [
		'hour-week-placement-unknown' => 'These hours could not be linked to a placement. Choose your placement again or ask the school.',
		'hour-week-lookup-failed' => 'The placement for these hours could not be checked. Try again later.',
		'hour-week-owner-missing' => 'A week of hours needs the student it is about and the school it belongs to.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService          $objectService  Reads the placement.
	 * @param LoggerInterface        $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp, then enforce, the owner fields of a week of hours.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true || $this->isWeek(entity: $this->entityOf(event: $event)) === false) {
			return;
		}

		$payload = array_merge(($this->entityOf(event: $event)->getObject() ?? []), $event->getModifiedData());

		try {
			$placement = $this->placement(id: $this->text(value: ($payload['bpvPlacementId'] ?? null)));
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[HourWeekSubmissionStamp] Could not read the placement of a week of hours: {msg}',
				['msg' => $exception->getMessage()]
			);
			$this->refuse(event: $event, reason: 'hour-week-lookup-failed');
			return;
		}

		if ($placement === null) {
			$this->refuse(event: $event, reason: 'hour-week-placement-unknown');
			return;
		}

		$stamp = $this->stamp(event: $event, payload: $payload, placement: $placement);
		if ($this->ownerMissing(data: array_merge($payload, $stamp)) === true) {
			$this->refuse(event: $event, reason: 'hour-week-owner-missing');
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $stamp));
	}//end handle()

	/**
	 * What this write stores about whose week it is: the student and the
	 * school from the placement, and on a create the moment and the person who
	 * entered it. An update keeps both, because the trainer's decision is not
	 * a new submission.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event     The write event.
	 * @param array<string, mixed>                    $payload   The week being written.
	 * @param array<string, mixed>                    $placement The placement it names.
	 *
	 * @return array<string, mixed>
	 */
	private function stamp(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $payload, array $placement): array {
		$learnerRef = $this->text(value: ($placement['learnerRef'] ?? null));
		$stamp = [
			'learnerRef' => $learnerRef,
			'tenant_id' => $this->text(value: ($placement['tenant_id'] ?? null)),
		];

		if ($event instanceof ObjectUpdatingEvent === true) {
			return $stamp;
		}

		// Whoever entered the week is the student whose week it is: the schema
		// holds a LearnerProfile uuid, which is the only thing a staff member
		// entering it on her behalf could carry either.
		$stamp['submittedBy'] = $learnerRef;
		$stamp['submittedAt'] = $this->text(value: ($payload['submittedAt'] ?? null));
		if ($stamp['submittedAt'] === '') {
			$stamp['submittedAt'] = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
		}

		return $stamp;
	}//end stamp()

	/**
	 * Whether the week still lacks the student or the school after stamping.
	 *
	 * @param array<string, mixed> $data The week as it would be saved.
	 *
	 * @return bool
	 */
	private function ownerMissing(array $data): bool {
		return $this->text(value: ($data['learnerRef'] ?? null)) === ''
			|| $this->text(value: ($data['tenant_id'] ?? null)) === '';
	}//end ownerMissing()

	/**
	 * One placement by uuid, RBAC off (a portal write has no session), or null.
	 *
	 * @param string $id The placement uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function placement(string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::PLACEMENT_SCHEMA],
				'ids' => [$id],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = $this->row(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end placement()

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $object The row.
	 *
	 * @return array<string, mixed>
	 */
	private function row(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$row = $object->jsonSerialize();
			if (is_array($row) === true) {
				return $row;
			}
		}

		return [];
	}//end row()

	/**
	 * Refuse the write, with a reason a reader can act on.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The write event.
	 * @param string                                  $reason One of the REFUSALS keys.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $reason): void {
		$event->setErrors(['reason' => $reason, 'message' => self::REFUSALS[$reason]]);
		$event->stopPropagation();

		$this->logger->info('[HourWeekSubmissionStamp] Refused a week of hours: {reason}', ['reason' => $reason]);
	}//end refuse()

	/**
	 * Whether the entity being written is a week of BPV hours.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return bool
	 */
	private function isWeek(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::WEEK_SCHEMA;
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours.
			return false;
		}
	}//end isWeek()

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
	 * A string value, trimmed, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
