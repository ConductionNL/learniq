<?php

/**
 * Learniq conference invitation sync
 *
 * Keeps the per-child invitation rows of a conference round
 * (ConferenceInvitations) in step after a write:
 * - a round is created or changes (its invited pupils, its state, its name or
 *   its last booking day): the round's rows follow;
 * - a conversation time of a child changes state (booked, acknowledged,
 *   cancelled, declined, planned): that round's rows follow, so a child who
 *   has a time no longer asks the guardian for one, and a cancelled time asks
 *   again.
 *
 * Writes to any other schema, and a free time nobody holds, are left alone.
 *
 * ADR-031 legitimate exception: cross-object write bridge.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ConferenceInvitations;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-syncs a round's invitation rows when the round or a child's time moves.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
 */
class ConferenceInvitationSync implements IEventListener {

	private const REGISTER = 'learniq';

	private const ROUND_SCHEMA = 'conference-round';

	private const SLOT_SCHEMA = 'conference-slot';

	/**
	 * The round fields an invitation depends on.
	 */
	private const ROUND_FIELDS = ['invitedLearnerRefs', 'lifecycle', 'name', 'bookingClosesAt', 'bookingMode', 'tenant_id'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService $objectService Reads the slot's round.
	 * @param ConferenceInvitations $invitations Writes the round's invitation rows.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objectService,
		private readonly ConferenceInvitations $invitations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a created or updated round or slot.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @listener-placement inline correctness: the guardian books a time and
	 * returns to her overview, which reads the invitation rows right after
	 * the write; deferred, the child she just booked for would still ask for
	 * a time. The work is bounded: it runs only when a round changes or a
	 * child's time changes state, and does at most three reads and one save
	 * per invited child whose row changed (normally one).
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
	 */
	public function handle(Event $event): void {
		[$new, $old] = $this->entities(event: $event);
		if ($new === null) {
			return;
		}

		$schema = $this->schemaResolver->guardSchemaSlug(entity: $new);
		$round = null;
		if ($schema === self::ROUND_SCHEMA) {
			$round = $this->changedRound(new: $new->jsonSerialize(), old: $old?->jsonSerialize());
		} else if ($schema === self::SLOT_SCHEMA) {
			$round = $this->roundOfMovedSlot(new: $new->jsonSerialize(), old: $old?->jsonSerialize());
		}

		if ($round === null) {
			return;
		}

		try {
			$this->invitations->syncRound(round: $round);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[ConferenceInvitationSync] Invitations of round {round} could not be written: {msg}',
				['round' => ($round['id'] ?? ''), 'msg' => $exception->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The new and old entity of a create or update.
	 *
	 * @param Event $event The event.
	 *
	 * @return array{0: ObjectEntity|null, 1: ObjectEntity|null}
	 */
	private function entities(Event $event): array {
		if ($event instanceof ObjectCreatedEvent) {
			return [$event->getObject(), null];
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return [$event->getNewObject(), $event->getOldObject()];
		}

		return [null, null];
	}//end entities()

	/**
	 * The round when a field an invitation depends on changed (or it is new).
	 *
	 * @param array<string, mixed> $new The round after the write.
	 * @param array<string, mixed>|null $old The round before, or null on create.
	 *
	 * @return array<string, mixed>|null
	 */
	private function changedRound(array $new, ?array $old): ?array {
		if ($old === null) {
			return $new;
		}

		foreach (self::ROUND_FIELDS as $field) {
			if (($new[$field] ?? null) !== ($old[$field] ?? null)) {
				return $new;
			}
		}

		return null;
	}//end changedRound()

	/**
	 * The round of a slot whose child holds or gives back a time.
	 *
	 * @param array<string, mixed> $new The slot after the write.
	 * @param array<string, mixed>|null $old The slot before, or null on create.
	 *
	 * @return array<string, mixed>|null
	 */
	private function roundOfMovedSlot(array $new, ?array $old): ?array {
		$child = (string)($new['learnerRef'] ?? ($old['learnerRef'] ?? ''));
		$moved = (($new['lifecycle'] ?? null) !== ($old['lifecycle'] ?? null)
			|| ($new['learnerRef'] ?? null) !== ($old['learnerRef'] ?? null));
		$roundId = (string)($new['conferenceRoundId'] ?? '');
		if ($child === '' || $moved === false || $roundId === '') {
			return null;
		}

		try {
			$round = $this->objectService->find(
				id: $roundId,
				register: self::REGISTER,
				schema: self::ROUND_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ConferenceInvitationSync] Round {round} of a slot could not be read: {msg}',
				['round' => $roundId, 'msg' => $exception->getMessage()]
			);
			return null;
		}

		if ($round === null) {
			return null;
		}

		return $round->jsonSerialize();
	}//end roundOfMovedSlot()
}//end class
