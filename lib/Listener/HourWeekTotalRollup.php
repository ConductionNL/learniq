<?php

/**
 * Learniq HourWeekTotalRollup
 *
 * Keeps `BpvPlacement.hoursApprovedTotal` equal to the sum of the approved
 * hours of that placement's weeks, and, since bpv-hours-match-the-board,
 * `hoursWaitingTotal` (hours of weeks still waiting for the trainer) and
 * `hoursReturnedTotal` (hours of weeks she sent back without approving them):
 * the three parts of the student's hours bar on the board.
 *
 * WHY A STORED TOTAL AND NOT A COUNT AT READ TIME. The trainer's overview
 * draws a progress card from two fields of one row (`hoursApprovedTotal`
 * against `agreedHours`), and portaliq reads that row and nothing else: a
 * total that lived only in a query could not reach it. The listener is
 * therefore where the number is kept true, on every write of a week.
 *
 * A placement with no agreed total still gets its sum; the card then shows the
 * hours and no bar, which is what the change proposed.
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
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-hours-are-shown-against-the-hours-that-were-agreed
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes a placement's approved hours whenever one of its weeks is written.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-hours-are-shown-against-the-hours-that-were-agreed
 */
class HourWeekTotalRollup implements IEventListener {

	private const REGISTER = 'learniq';

	private const WEEK_SCHEMA = 'bpv-hour-week';

	private const PLACEMENT_SCHEMA = 'bpv-placement';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService          $objectService  Reads the weeks and writes the placement.
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
	 * Recompute the total of the placement this week belongs to.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @listener-placement inline correctness — the trainer approves a week and
	 * the page she lands on next reads the placement row she approved it
	 * against. Deferring the total to a queue would show her the figure from
	 * before her own decision for however long the queue takes, which is the
	 * one thing a progress figure must never do: it is read as "this is where
	 * your student stands". The work is bounded too: one filtered read of the
	 * weeks of ONE placement, capped at 500, a sum over them, and a write only
	 * when the number actually moved.
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-hours-are-shown-against-the-hours-that-were-agreed
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false && $event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$entity = $event->getObject();
		if ($event instanceof ObjectUpdatedEvent === true) {
			$entity = $event->getNewObject();
		}

		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $entity) !== self::WEEK_SCHEMA) {
				return;
			}
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours.
			return;
		}

		$week = ($entity->getObject() ?? []);
		$placementId = (string)($week['bpvPlacementId'] ?? '');
		if ($placementId === '') {
			return;
		}

		try {
			$this->writeTotals(placementId: $placementId, totals: $this->sumsFor(placementId: $placementId));
		} catch (Throwable $exception) {
			// A total that could not be recomputed is stale, never wrong by
			// invention: the stored value stays and the failure is reported.
			$this->logger->warning(
				'[HourWeekTotalRollup] Could not recompute the hours of placement {id}: {msg}',
				['id' => $placementId, 'msg' => $exception->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The approved, waiting and returned hours of every week of one placement.
	 *
	 * Approved: `hoursApproved` of every week that has it. Waiting: the hours
	 * submitted on a week still `submitted`. Returned: the hours submitted on
	 * a `rejected` week less what was approved of them (none). A `corrected`
	 * week counts only its approved number.
	 *
	 * @param string $placementId The placement.
	 *
	 * @return array{hoursApprovedTotal: float, hoursWaitingTotal: float, hoursReturnedTotal: float}
	 *
	 * @throws Throwable When the weeks cannot be read.
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-the-hours-bar-shows-approved-waiting-and-returned-hours
	 */
	public function sumsFor(string $placementId): array {
		$weeks = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::WEEK_SCHEMA,
					'bpvPlacementId' => $placementId,
				],
				'limit' => 500,
			],
			_rbac: false,
			_multitenancy: false
		);

		$totals = ['hoursApprovedTotal' => 0.0, 'hoursWaitingTotal' => 0.0, 'hoursReturnedTotal' => 0.0];
		foreach ($weeks as $object) {
			$row = $object;
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$row = (array)$object->jsonSerialize();
			}

			if (is_array($row) === false) {
				continue;
			}

			$approved  = self::hours(value: ($row['hoursApproved'] ?? null));
			$submitted = self::hours(value: ($row['hoursSubmitted'] ?? null));
			$totals['hoursApprovedTotal'] += $approved;
			$lifecycle = (string)($row['lifecycle'] ?? '');
			if ($lifecycle === 'submitted') {
				$totals['hoursWaitingTotal'] += $submitted;
			} else if ($lifecycle === 'rejected') {
				$totals['hoursReturnedTotal'] += max(0.0, $submitted - $approved);
			}
		}

		return $totals;
	}//end sumsFor()

	/**
	 * A number of hours, or zero when the field holds none.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return float
	 */
	private static function hours(mixed $value): float {
		if (is_numeric($value) === false) {
			return 0.0;
		}

		return (float)$value;
	}//end hours()

	/**
	 * Write the totals on the placement, only when one of them moved.
	 *
	 * @param string                                                                              $placementId The placement.
	 * @param array{hoursApprovedTotal: float, hoursWaitingTotal: float, hoursReturnedTotal: float} $totals      The sums of its weeks.
	 *
	 * @return void
	 *
	 * @throws Throwable When the placement cannot be written.
	 */
	private function writeTotals(string $placementId, array $totals): void {
		$placements = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::PLACEMENT_SCHEMA],
				'ids' => [$placementId],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($placements as $object) {
			$row = $object;
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$row = (array)$object->jsonSerialize();
			}

			if (is_array($row) === false) {
				continue;
			}

			$moved = false;
			foreach ($totals as $field => $value) {
				if ((float)($row[$field] ?? -1) !== $value) {
					$moved = true;
				}
			}

			if ($moved === false) {
				// Nothing changed, so nothing is written: a write here would
				// raise another event and walk back into this listener.
				return;
			}

			$this->objectService->saveObject(
				object: array_merge($row, $totals),
				register: self::REGISTER,
				schema: self::PLACEMENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			return;
		}
	}//end writeTotals()
}//end class
