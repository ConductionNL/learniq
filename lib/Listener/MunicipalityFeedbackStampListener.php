<?php

/**
 * Learniq MunicipalityFeedbackStampListener
 *
 * Stamps `municipalityFeedback.recordedBy` and `.receivedAt` onto a
 * reported AttendanceFlag after its recordMunicipalityFeedback transition.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use RuntimeException;

/**
 * Writes who recorded the municipality's feedback, and when it was received.
 *
 * `recordMunicipalityFeedback` is a self-loop (succeeded to succeeded).
 * MunicipalityFeedbackGuard used to write this stamp into a mutable payload,
 * but OpenRegister calls guards by value, and on a self-loop its validation and
 * action listeners both return before a guard or action runs. TransitionEngine
 * does dispatch ObjectTransitionedEvent after the save for every transition,
 * self-loops included, so the stamp is written here and saved (learniq#983).
 * `recordedBy` is always the acting user, never a caller-supplied value;
 * `receivedAt` is the server's UTC time unless the caller supplied one.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/attendance/spec.md#requirement-the-municipalitys-feedback-on-a-leerplicht-report-is-recorded-on-the-attendance-flag
 */
class MunicipalityFeedbackStampListener implements IEventListener {

	/**
	 * The transition inputs this listener reads from the saved flag.
	 *
	 * @var list<string>
	 */
	public const TRANSITION_INPUTS = ['municipalityFeedback'];

	private const LEARNIQ_REGISTER = 'learniq';
	private const FLAG_SCHEMA = 'attendance-flag';
	private const ACTION = 'recordMunicipalityFeedback';

	/**
	 * Constructor.
	 *
	 * @param ObjectService          $objectService  OR object access service.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ListenerSchemaResolver $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Stamp and save the feedback on a recordMunicipalityFeedback transition.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the transition has no acting user.
	 *
	 * @spec openspec/changes/archive/2026-07-13-verzuim-report-composer/tasks.md#task-2.2
	 * @spec openspec/specs/attendance/spec.md#requirement-the-municipalitys-feedback-on-a-leerplicht-report-is-recorded-on-the-attendance-flag
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false || $event->getAction() !== self::ACTION) {
			return;
		}

		$entity = $event->getObject();
		if ($this->schemaResolver->guardSchemaSlug(entity: $entity) !== self::FLAG_SCHEMA) {
			return;
		}

		$actor = (string)($event->getUserId() ?? '');
		if ($actor === '') {
			throw new RuntimeException(
				sprintf('Municipality feedback on attendance flag %s was recorded without a user, so it can not be attributed.', (string)$entity->getUuid())
			);
		}

		$data = $entity->getObject();
		$feedback = ($data['municipalityFeedback'] ?? []);
		if (is_array($feedback) === false) {
			$feedback = [];
		}

		$feedback['recordedBy'] = $actor;
		if (empty($feedback['receivedAt']) === true) {
			$feedback['receivedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
		}

		$data['municipalityFeedback'] = $feedback;

		$this->objectService->saveObject(
			object: $data,
			register: self::LEARNIQ_REGISTER,
			schema: self::FLAG_SCHEMA,
			uuid: $entity->getUuid()
		);
	}//end handle()
}//end class
