<?php

/**
 * Learniq conference signup portal stamp
 *
 * A guardian without a Nextcloud account books a parent-teacher conversation
 * from the parent portal (portaliq, learniq's `parent` contribution action
 * `createConferenceSignup`). Portaliq stamps the guardian's own learniq
 * reference into `guardianRef` (scopeClaim `guardianRef`) and the guardian
 * names the child (`learnerRef`) and the round. The Nextcloud path gates the
 * `submit` transition with ConferenceSignupGuardianGuard, which needs a
 * session user; a portal write has none, so this listener does the same
 * check from the references and stamps what the scheduling generator needs.
 *
 * On a portal create (no session user, a `guardianRef`, no `learnerId`) it:
 * - refuses unless the child lists the guardian in `guardianRefs`;
 * - refuses unless the round is `booking-open` and invited the child;
 * - stamps `learnerId`, `guardianId`, `tenant_id` and the lifecycle
 *   `submitted`, so the generator considers the signup;
 * - keeps only requested teachers the round offers, and when none are left,
 *   requests every teacher of the round who teaches the child's group, or
 *   else every teacher of the round.
 *
 * Every other write is left alone.
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
 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks and stamps a guardian's conference signup from the portal.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
 */
class ConferenceSignupPortalStamp implements IEventListener {

	private const REGISTER = 'learniq';

	private const SIGNUP_SCHEMA = 'conference-signup';

	private const ROUND_SCHEMA = 'conference-round';

	private const COHORT_SCHEMA = 'cohort';

	/**
	 * The refusals this listener can give, keyed by reason.
	 */
	private const REFUSALS = [
		'signup-guardian-unknown' => 'You can only book a conversation for your own child.',
		'signup-round-closed' => 'Booking for this round is not open, or your child is not invited to it.',
		'signup-lookup-failed' => 'The booking could not be checked. Try again later.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolver $profiles LearnerProfile by uuid.
	 * @param ObjectService $objectService Reads the round and the child's group.
	 * @param IUserSession $userSession Tells a portal write (no session) from an app write.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LearnerRefResolver $profiles,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check and stamp a portal signup create.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false || $event->isPropagationStopped() === true) {
			return;
		}

		$entity = $event->getObject();
		if ($this->isSignup(entity: $entity) === false) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		if ($this->isPortalSignup(payload: $payload) === false) {
			return;
		}

		try {
			$outcome = $this->outcomeFor(payload: $payload);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ConferenceSignupPortalStamp] Could not check a portal signup: {msg}',
				['msg' => $exception->getMessage()]
			);
			$outcome = ['refuse' => 'signup-lookup-failed'];
		}

		if (isset($outcome['refuse']) === true) {
			$event->setErrors(['reason' => $outcome['refuse'], 'message' => self::REFUSALS[$outcome['refuse']]]);
			$event->stopPropagation();
			$this->logger->info('[ConferenceSignupPortalStamp] Refused a portal signup: {reason}', ['reason' => $outcome['refuse']]);
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $outcome['stamp']));
	}//end handle()

	/**
	 * A portal signup: no session user, a guardianRef, a learnerRef and no
	 * learnerId. A signed-in caller never takes this path.
	 *
	 * @param array<string, mixed> $payload The signup being created.
	 *
	 * @return bool
	 */
	private function isPortalSignup(array $payload): bool {
		if ($this->userSession->getUser() !== null || self::text(value: ($payload['learnerId'] ?? null)) !== '') {
			return false;
		}

		return self::text(value: ($payload['guardianRef'] ?? null)) !== ''
			&& self::text(value: ($payload['learnerRef'] ?? null)) !== '';
	}//end isPortalSignup()

	/**
	 * What to stamp on the signup, or why to refuse it.
	 *
	 * @param array<string, mixed> $payload The signup being created.
	 *
	 * @return array{stamp?: array<string, mixed>, refuse?: string}
	 */
	private function outcomeFor(array $payload): array {
		$guardianRef = self::text(value: $payload['guardianRef']);
		$child = $this->profiles->byRef(learnerRef: self::text(value: $payload['learnerRef']));
		if ($child === null || in_array($guardianRef, (array)($child['guardianRefs'] ?? []), true) === false) {
			return ['refuse' => 'signup-guardian-unknown'];
		}

		$round = $this->row(id: self::text(value: ($payload['conferenceRoundId'] ?? null)), schema: self::ROUND_SCHEMA);
		if ($round === null
			|| ($round['lifecycle'] ?? '') !== 'booking-open'
			|| in_array((string)$child['id'], (array)($round['invitedLearnerRefs'] ?? []), true) === false
		) {
			return ['refuse' => 'signup-round-closed'];
		}

		$guardian = $this->profiles->byRef(learnerRef: $guardianRef);

		return [
			'stamp' => [
				'learnerId' => (string)$child['ncUserId'],
				'learnerRef' => (string)$child['id'],
				'guardianId' => self::text(value: ($guardian['ncUserId'] ?? null)),
				'tenant_id' => self::text(value: ($round['tenant_id'] ?? null)),
				'requestedTeacherIds' => $this->requestedTeachers(
					requested: (array)($payload['requestedTeacherIds'] ?? []),
					round: $round,
					learnerId: (string)$child['ncUserId']
				),
				'lifecycle' => 'submitted',
			],
		];
	}//end outcomeFor()

	/**
	 * The teachers this signup asks for: the requested ones the round
	 * offers; else the round's teachers of the child's group; else all the
	 * round's teachers.
	 *
	 * @param array<int, mixed> $requested What the guardian asked for.
	 * @param array<string, mixed> $round The round.
	 * @param string $learnerId The child's Nextcloud user id.
	 *
	 * @return array<int, string>
	 */
	private function requestedTeachers(array $requested, array $round, string $learnerId): array {
		$offered = array_values(array_filter((array)($round['teacherIds'] ?? []), 'is_string'));
		$kept = array_values(array_intersect(array_filter($requested, 'is_string'), $offered));
		if ($kept !== []) {
			return $kept;
		}

		$ofGroup = [];
		foreach ((array)($round['cohortIds'] ?? []) as $cohortId) {
			$cohort = $this->row(id: (string)$cohortId, schema: self::COHORT_SCHEMA);
			if ($cohort === null || in_array($learnerId, (array)($cohort['learnerIds'] ?? []), true) === false) {
				continue;
			}

			$ofGroup = array_merge($ofGroup, array_intersect((array)($cohort['teacherIds'] ?? []), $offered));
		}

		if ($ofGroup !== []) {
			return array_values(array_unique($ofGroup));
		}

		return $offered;
	}//end requestedTeachers()

	/**
	 * One learniq object as an array, or null.
	 *
	 * @param string $id The object uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function row(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		$object = $this->objectService->find(
			id: $id,
			register: self::REGISTER,
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);
		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end row()

	/**
	 * Whether the entity is a ConferenceSignup. Not knowing the schema is not
	 * knowing it is ours, so another app's writes are never touched.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isSignup(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::SIGNUP_SCHEMA;
		} catch (Throwable $exception) {
			return false;
		}
	}//end isSignup()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()
}//end class
