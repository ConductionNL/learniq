<?php

/**
 * Learniq BPV Visit Phase Rollup
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A placement's phase depends on its assessment visits, which live in their
 * own schema. When a visit is written, its placement is saved again so
 * BpvPlacementPhaseStamp works its phase out from the visit as it now is.
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

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Saves a visit's placement again, unchanged, so its phase is recomputed.
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
class BpvVisitPhaseRollup implements IEventListener {

	/**
	 * The register.
	 */
	private const REGISTER = 'learniq';

	/**
	 * The visit schema.
	 */
	private const VISIT_SCHEMA = 'bpv-visit';

	/**
	 * The placement schema.
	 */
	private const PLACEMENT_SCHEMA = 'bpv-placement';

	/**
	 * The fields BpvPlacementPhaseStamp owns; left out so the stamp writes them afresh.
	 *
	 * @var array<string, bool>
	 */
	private const STAMPED = ['phase' => true, 'phaseDueAt' => true, 'lateLabel' => true, '@self' => true, 'id' => true];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells a visit from other objects.
	 * @param ObjectService                   $objectService  Reads and saves the placement.
	 * @param LoggerInterface                 $logger         Reports a placement that could not be saved.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Save the placement of a written visit again.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
	 */
	public function handle(Event $event): void {
		$entity = null;
		if ($event instanceof ObjectUpdatedEvent === true) {
			$entity = $event->getNewObject();
		} else if ($event instanceof ObjectCreatedEvent === true) {
			$entity = $event->getObject();
		}

		if ($entity === null || $this->isVisit(entity: $entity) === false) {
			return;
		}

		$placementId = trim((string) (($entity->getObject() ?? [])['bpvPlacementId'] ?? ''));
		if ($placementId === '') {
			return;
		}

		try {
			$this->resave(placementId: $placementId);
		} catch (Throwable $exception) {
			// The stored phase stays until the next save of the placement.
			$this->logger->warning(
				'[BpvVisitPhaseRollup] Could not recompute the phase of placement {id}: {msg}',
				['id' => $placementId, 'msg' => $exception->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Save the placement with its own data, without the stamped fields.
	 *
	 * @param string $placementId The placement id.
	 *
	 * @return void
	 */
	private function resave(string $placementId): void {
		$found = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::PLACEMENT_SCHEMA],
				'ids' => [$placementId],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($found as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			if ($row === []) {
				continue;
			}

			$this->objectService->saveObject(
				object: array_diff_key($row, self::STAMPED),
				register: self::REGISTER,
				schema: self::PLACEMENT_SCHEMA,
				uuid: $placementId,
				_rbac: false,
				_multitenancy: false
			);
		}

	}//end resave()

	/**
	 * Whether the entity is a visit.
	 *
	 * @param object $entity The entity.
	 *
	 * @return bool
	 */
	private function isVisit(object $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::VISIT_SCHEMA;
		} catch (Throwable $exception) {
			return false;
		}

	}//end isVisit()
}//end class
