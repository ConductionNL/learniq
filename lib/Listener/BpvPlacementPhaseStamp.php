<?php

/**
 * Learniq BPV Placement Phase Stamp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Writes a placement's phase, its due date and its late line on every save,
 * so the board card moves by itself when a fact changes ("schuift vanzelf
 * door"). A manual move from the board is kept until the next fact change
 * and logged with who and when.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Bpv\PlacementPhase;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps `phase`, `phaseDueAt` and `lateLabel` on a placement before it is saved.
 *
 * The three fields are the server's: a value sent for them is replaced. A
 * caller moves a card by sending `phaseOverride: {phase}`; the stamp records
 * who moved it and when, ties the move to the facts as they are now, and
 * appends it to `phaseMoves`. The move holds until a fact changes.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link    https://conduction.nl
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 *
 * @template-implements IEventListener<Event>
 */
class BpvPlacementPhaseStamp implements IEventListener {

	/**
	 * The register.
	 */
	private const REGISTER = 'learniq';

	/**
	 * The placement schema.
	 */
	private const PLACEMENT_SCHEMA = 'bpv-placement';

	/**
	 * The visit schema.
	 */
	private const VISIT_SCHEMA = 'bpv-visit';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells a placement from other objects.
	 * @param ObjectService                   $objectService  Reads the placement's visits.
	 * @param ITimeFactory                    $timeFactory    The server's clock.
	 * @param IUserSession                    $userSession    Who moves a card.
	 * @param LoggerInterface                 $logger         Reports a visit lookup that failed.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $timeFactory,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the phase on a placement being saved.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = (new WrittenObject())->entityOf(event: $event);
		if ($event->isPropagationStopped() === true || $this->isPlacement(entity: $entity) === false) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$visits  = $this->visits(placementId: (string) ($entity->getUuid() ?? ''));
		if ($visits === null) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $this->stamp(payload: $payload, visits: $visits, sent: $event->getModifiedData())));

	}//end handle()

	/**
	 * The fields to stamp: the derived phase, or a manual move that still holds.
	 *
	 * @param array<string, mixed>             $payload The placement as it will be saved.
	 * @param array<int, array<string, mixed>> $visits  Its visits.
	 * @param array<string, mixed>             $sent    What this save sent.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
	 */
	public function stamp(array $payload, array $visits, array $sent): array {
		$phases  = new PlacementPhase();
		$today   = (new DateTimeImmutable())->setTimestamp($this->timeFactory->getTime())->setTime(0, 0);
		$derived = $phases->derive(placement: $payload, visits: $visits, today: $today);
		$facts   = $phases->factsKey(placement: $payload, visits: $visits);
		$stamp   = $derived + ['phaseOverride' => null, 'phaseMoves' => array_values((array) ($payload['phaseMoves'] ?? []))];

		$override = ($payload['phaseOverride'] ?? null);
		if (is_array($override) === false || in_array(($override['phase'] ?? null), PlacementPhase::PHASES, true) === false) {
			return $stamp;
		}

		if (array_key_exists('phaseOverride', $sent) === true && ($override['factsKey'] ?? null) === null) {
			// A new move: who and when are the server's, and it holds for the facts as they are now.
			$override = [
				'phase' => $override['phase'],
				'movedBy' => (string) ($this->userSession->getUser()?->getUID() ?? ''),
				'movedAt' => (new DateTimeImmutable())->setTimestamp($this->timeFactory->getTime())->format('c'),
				'factsKey' => $facts,
			];
			$stamp['phaseMoves'][] = array_diff_key($override, ['factsKey' => true]) + ['from' => $derived['phase']];
		}

		if (($override['factsKey'] ?? null) !== $facts) {
			return $stamp;
		}

		$stamp['phase']         = $override['phase'];
		$stamp['phaseOverride'] = $override;

		return $stamp;

	}//end stamp()

	/**
	 * The placement's visits, or null when they could not be read (the stored phase then stays).
	 *
	 * @param string $placementId The placement id ('' for a new placement).
	 *
	 * @return array<int, array<string, mixed>>|null The visits.
	 */
	private function visits(string $placementId): ?array {
		if ($placementId === '') {
			return [];
		}

		try {
			$found = $this->objectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => self::VISIT_SCHEMA, 'bpvPlacementId' => $placementId],
					'limit' => 200,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BpvPlacementPhaseStamp] Could not read the visits of placement {id}: {msg}',
				['id' => $placementId, 'msg' => $exception->getMessage()]
			);
			return null;
		}

		$rows = [];
		foreach ($found as $object) {
			$rows[] = (new SerialisedRow())->asArray(object: $object);
		}

		return $rows;

	}//end visits()

	/**
	 * Whether the entity is a placement.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return bool
	 */
	private function isPlacement(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::PLACEMENT_SCHEMA;
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours.
			return false;
		}

	}//end isPlacement()
}//end class
