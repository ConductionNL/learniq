<?php

/**
 * Learniq HourWeekTotalRollup
 *
 * Keeps `BpvPlacement.hoursApprovedTotal` equal to the sum of the approved
 * hours of that placement's weeks.
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
			$this->writeTotal(placementId: $placementId, total: $this->sumFor(placementId: $placementId));
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
	 * The approved hours of every week of one placement.
	 *
	 * @param string $placementId The placement.
	 *
	 * @return float
	 *
	 * @throws Throwable When the weeks cannot be read.
	 */
	private function sumFor(string $placementId): float {
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

		$total = 0.0;
		foreach ($weeks as $object) {
			$row = $object;
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$row = (array)$object->jsonSerialize();
			}

			if (is_array($row) === false || is_numeric(($row['hoursApproved'] ?? null)) === false) {
				continue;
			}

			$total += (float)$row['hoursApproved'];
		}

		return $total;
	}//end sumFor()

	/**
	 * Write the total on the placement.
	 *
	 * @param string $placementId The placement.
	 * @param float  $total       The sum of its approved hours.
	 *
	 * @return void
	 *
	 * @throws Throwable When the placement cannot be written.
	 */
	private function writeTotal(string $placementId, float $total): void {
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

			if ((float)($row['hoursApprovedTotal'] ?? -1) === $total) {
				// Nothing changed, so nothing is written: a write here would
				// raise another event and walk back into this listener.
				return;
			}

			$this->objectService->saveObject(
				object: array_merge($row, ['hoursApprovedTotal' => $total]),
				register: self::REGISTER,
				schema: self::PLACEMENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			return;
		}
	}//end writeTotal()
}//end class
