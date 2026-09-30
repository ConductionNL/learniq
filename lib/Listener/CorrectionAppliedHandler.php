<?php

/**
 * Learniq Correction Applied Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent on a GradeEntry
 * `publish` or `republish`. When an approved DataCorrectionRequest covered
 * that publish (the report period lock guard let it through on it), the
 * request moves to `applied` with who published it and when, and the grade
 * entry names the request in `correctionRequestId`. The grade entry's
 * history then shows the changed value next to the request that holds the
 * requester, the approver and the reason.
 *
 * Both writes run as the system: the publishing teacher may not update a
 * correction request, and the entry link is a fact of the publish, not an
 * edit by that teacher.
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Marks the correction that covered a grade publish as applied.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */
class CorrectionAppliedHandler implements IEventListener {

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemas   Resolves the event's register and schema to slugs.
	 * @param CorrectionApprovals    $approvals The approved correction for a grade entry.
	 * @param ObjectService          $objects   OpenRegister object access.
	 * @param LoggerInterface        $logger    PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemas,
		private readonly CorrectionApprovals $approvals,
		private readonly ObjectService $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mark the covering correction applied after a grade entry is published.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		if ($event->getTo() !== 'published'
			|| $this->schemas->eventRegister(event: $event) !== self::REGISTER
			|| $this->schemas->eventSchema(event: $event) !== 'grade-entry'
		) {
			return;
		}

		$entry     = $event->getObject()->jsonSerialize();
		$publisher = (string)($event->getUserId() ?? '');
		$request   = $this->approvals->approvedFor(entry: $entry, publisher: $publisher);
		if ($request === null) {
			return;
		}

		try {
			$this->markApplied(request: $request, entry: $entry, publisher: $publisher);
		} catch (Throwable $e) {
			// The grade is published and the request still reads approved: the
			// history shows both, and a second publish on it is refused by the
			// value check only if the value changes again. Log for the admin.
			$this->logger->error(
				'[CorrectionAppliedHandler] Correction {request} for grade entry {entry} could not be marked applied: {error}',
				['request' => $request['id'] ?? '', 'entry' => $entry['id'] ?? '', 'error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Move the request to applied and link it from the grade entry.
	 *
	 * @param array<string, mixed> $request   The approved correction request.
	 * @param array<string, mixed> $entry     The published grade entry.
	 * @param string               $publisher The uid of the person who published.
	 *
	 * @return void
	 */
	private function markApplied(array $request, array $entry, string $publisher): void {
		$requestId = (string)($request['id'] ?? ($request['uuid'] ?? ''));
		$now       = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

		$this->objects->saveObject(
			object: array_merge(
				$request,
				['lifecycle' => 'applied', 'appliedBy' => $publisher, 'appliedAt' => $now]
			),
			register: self::REGISTER,
			schema: 'data-correction-request',
			uuid: $requestId,
			_rbac: false
		);

		$this->objects->saveObject(
			object: array_merge($entry, ['correctionRequestId' => $requestId]),
			register: self::REGISTER,
			schema: 'grade-entry',
			uuid: (string)($entry['id'] ?? ($entry['uuid'] ?? '')),
			_rbac: false
		);
	}//end markApplied()
}//end class
