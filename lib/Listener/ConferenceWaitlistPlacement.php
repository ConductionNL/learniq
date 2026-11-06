<?php

/**
 * Learniq Conference Waitlist Placement
 *
 * A coordinator places a waitlisted conference signup by hand: they create a
 * ConferenceSlot for one requested teacher that names the signup. Once every
 * teacher the signup asked for has a slot that is not cancelled, the signup
 * is `scheduled`, as the schedule generator would have written it, so the
 * waitlist board stops listing it.
 *
 * ADR-031 legitimate exception: cross-object write bridge, the same shape as
 * ConferenceScheduleGenerator's signup write.
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
 * @spec openspec/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Schedules a waitlisted signup once its hand-placed slots meet every request.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
 */
class ConferenceWaitlistPlacement implements IEventListener {

	private const REGISTER = 'learniq';

	private const SLOT_SCHEMA = 'conference-slot';

	private const SIGNUP_SCHEMA = 'conference-signup';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService          $objectService  Reads the signup and its slots, writes the signup.
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
	 * Schedule the signup a new slot names, when it is waitlisted and now met.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $event->getObject()) !== self::SLOT_SCHEMA) {
				return;
			}

			$signupId = (string)(($event->getObject()->getObject() ?? [])['signupId'] ?? '');
			if ($signupId === '') {
				return;
			}

			$this->scheduleWhenMet(signupId: $signupId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ConferenceWaitlistPlacement] Could not update the signup of a placed slot: {msg}',
				['msg' => $exception->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Write the signup `scheduled` when it is waitlisted and every requested
	 * teacher has a slot that is not cancelled.
	 *
	 * @param string $signupId The ConferenceSignup uuid.
	 *
	 * @return void
	 */
	private function scheduleWhenMet(string $signupId): void {
		$signup = $this->objectService->find(
			id: $signupId,
			register: self::REGISTER,
			schema: self::SIGNUP_SCHEMA,
			_rbac: false,
			_multitenancy: false
		)?->jsonSerialize();
		if (is_array($signup) === false || ($signup['lifecycle'] ?? '') !== 'waitlisted') {
			return;
		}

		$requested = array_values(array_filter((array)($signup['requestedTeacherIds'] ?? []), 'is_string'));
		$placed = $this->placedTeachers(signupId: $signupId);
		if ($requested === [] || array_diff($requested, $placed) !== []) {
			return;
		}

		$signup['lifecycle'] = 'scheduled';
		unset($signup['@self'], $signup['id']);
		$this->objectService->saveObject(
			object: $signup,
			register: self::REGISTER,
			schema: self::SIGNUP_SCHEMA,
			uuid: $signupId,
			_rbac: false,
			_multitenancy: false
		);
	}//end scheduleWhenMet()

	/**
	 * The teachers that have a slot for the signup that is not cancelled.
	 *
	 * @param string $signupId The ConferenceSignup uuid.
	 *
	 * @return array<int, string>
	 */
	private function placedTeachers(string $signupId): array {
		$slots = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SLOT_SCHEMA,
					'signupId' => $signupId,
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		$teachers = [];
		foreach ($slots as $slot) {
			$data = $slot;
			if (is_array($slot) === false) {
				$data = $slot->jsonSerialize();
			}

			if (($data['lifecycle'] ?? '') !== 'cancelled' && is_string($data['teacherId'] ?? null) === true) {
				$teachers[] = $data['teacherId'];
			}
		}

		return $teachers;
	}//end placedTeachers()
}//end class
