<?php

/**
 * Learniq Invigilator Assignment Check
 *
 * Checks a new InvigilatorAssignment before it is saved. The colleague must
 * have stated availability in the sitting's test week that covers the whole
 * exam, and may not already hold an open or confirmed request for the same
 * exam. A new request always starts pending, whatever the client sends: only
 * the invigilator can confirm it (InvigilatorResponseGuard).
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
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-invigilator-assignment
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ExamSittingOverview;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a request for someone who is not available or already asked.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-invigilator-assignment
 */
class InvigilatorAssignmentCheck implements IEventListener {

	private const ASSIGNMENT_SCHEMA = 'invigilator-assignment';

	/**
	 * The refusals this check can give, keyed by reason.
	 */
	private const REFUSALS = [
		'invigilator-sitting-unknown' => 'The exam for this invigilation request does not exist.',
		'invigilator-not-available' => 'This colleague is not available for the whole exam. Ask them to add their availability first.',
		'invigilator-already-assigned' => 'This colleague has already been asked for this exam.',
		'invigilator-check-failed' => 'The availability for this request could not be checked. Try again later.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ExamSittingOverview $overview Sittings, bookings and availability.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ExamSittingOverview $overview,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check an InvigilatorAssignment create.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-invigilator-assignment
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false || $event->isPropagationStopped() === true) {
			return;
		}

		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $event->getObject()) !== self::ASSIGNMENT_SCHEMA) {
				return;
			}
		} catch (Throwable $exception) {
			return;
		}

		$request = array_merge(($event->getObject()->getObject() ?? []), $event->getModifiedData());

		try {
			$reason = $this->refusal(request: $request);
		} catch (Throwable $exception) {
			$this->logger->warning('[InvigilatorAssignmentCheck] Could not check a request: {msg}', ['msg' => $exception->getMessage()]);
			$reason = 'invigilator-check-failed';
		}

		if ($reason !== null) {
			$event->setErrors(['reason' => $reason, 'message' => self::REFUSALS[$reason]]);
			$event->stopPropagation();
			$this->logger->info('[InvigilatorAssignmentCheck] Refused an invigilation request: {reason}', ['reason' => $reason]);
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['lifecycle' => 'pending']));
	}//end handle()

	/**
	 * Why this request cannot be made, or null.
	 *
	 * @param array<string, mixed> $request The assignment being created.
	 *
	 * @return string|null A REFUSALS key.
	 */
	private function refusal(array $request): ?string {
		$sittingId = (string)($request['examSittingId'] ?? '');
		$invigilator = (string)($request['invigilatorId'] ?? '');

		$sitting = $this->overview->sitting(sittingId: $sittingId);
		if ($sitting === null) {
			return 'invigilator-sitting-unknown';
		}

		if (in_array($invigilator, $this->overview->bookedInvigilators(sittingId: $sittingId), true) === true) {
			return 'invigilator-already-assigned';
		}

		if (in_array($invigilator, $this->overview->coveringAvailability(sitting: $sitting), true) === false) {
			return 'invigilator-not-available';
		}

		return null;
	}//end refusal()
}//end class
