<?php

/**
 * Learniq WerkprocesAssessmentLearnerStamp
 *
 * Writes the learner's Nextcloud user id on every werkproces assessment, from
 * the BPV placement the assessment names.
 *
 * WHY. A WerkprocesAssessment names only its placement (`bpvPlacementId`), so
 * no read rule could match it to the signed-in learner, and the register's
 * staff-only block applied: a learner's own learning record
 * (GET /api/learning-records/me, read with the learner's rights) never showed
 * one of their assessments, and the portfolio's evidence picker could not
 * offer one. With `learnerId` on the row the schema can declare a read rule
 * for the learner's own confirmed assessments, and nothing wider.
 *
 * Posture, mirroring GradeEntryLearnerRefStamp:
 * - the server derives the value from the placement; a `learnerId` sent by
 *   the client is replaced, so nobody can hand an assessment to another
 *   learner;
 * - a placement that does not exist or names no learner gives null, so no
 *   learner matches (fail closed);
 * - the stamp never blocks the assessor's write. When the lookup fails on a
 *   create the value is null; on an update the stored value is kept.
 *
 * ADR-031 exception: a cross-schema lookup (an assessment to its placement)
 * that no schema calculation can express.
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives a werkproces assessment's learner from its placement on every write.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
 */
class WerkprocesAssessmentLearnerStamp implements IEventListener {

	private const REGISTER = 'learniq';

	private const ASSESSMENT_SCHEMA = 'werkproces-assessment';

	private const PLACEMENT_SCHEMA = 'bpv-placement';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService          $objectService  Reads the placement.
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
	 * Stamp `learnerId` on a werkproces assessment being created or updated.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		if ($event->isPropagationStopped() === true || $this->isAssessment(entity: $entity) === false) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$placementId = $this->text(value: ($payload['bpvPlacementId'] ?? null));

		try {
			$learnerId = $this->learnerOfPlacement(placementId: $placementId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[WerkprocesAssessmentLearnerStamp] Could not read placement {id}: {msg}',
				['id' => $placementId, 'msg' => $exception->getMessage()]
			);
			$learnerId = $this->storedLearner(event: $event, placementId: $placementId);
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['learnerId' => $learnerId]));
	}//end handle()

	/**
	 * The Nextcloud user id of the learner on a placement, or null when the
	 * placement does not exist or names nobody. Reads without RBAC: the
	 * assessor (a praktijkopleider) may write an assessment without reading
	 * the placement, and only the user id leaves this method. Errors from
	 * OpenRegister propagate, so a caller can tell "nobody" from "could not
	 * look".
	 *
	 * @param string $placementId The BpvPlacement uuid.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
	 */
	public function learnerOfPlacement(string $placementId): ?string {
		if ($placementId === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::PLACEMENT_SCHEMA],
				'ids' => [$placementId],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = $this->row(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) !== $placementId) {
				continue;
			}

			$learnerId = $this->text(value: ($row['learnerId'] ?? null));
			if ($learnerId === '') {
				return null;
			}

			return $learnerId;
		}

		return null;
	}//end learnerOfPlacement()

	/**
	 * After a failed lookup: on an update that keeps the placement, the value
	 * already stored; otherwise null (fail closed).
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event       The write event.
	 * @param string                                  $placementId The placement being written.
	 *
	 * @return string|null
	 */
	private function storedLearner(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $placementId): ?string {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return null;
		}

		$old = ($event->getOldObject()?->getObject() ?? []);
		if ($this->text(value: ($old['bpvPlacementId'] ?? null)) !== $placementId) {
			return null;
		}

		$stored = $this->text(value: ($old['learnerId'] ?? null));
		if ($stored === '') {
			return null;
		}

		return $stored;
	}//end storedLearner()

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $object The row.
	 *
	 * @return array<string, mixed>
	 */
	private function row(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$row = $object->jsonSerialize();
			if (is_array($row) === true) {
				return $row;
			}
		}

		return [];
	}//end row()

	/**
	 * Whether the entity being written is a werkproces assessment.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return bool
	 */
	private function isAssessment(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::ASSESSMENT_SCHEMA;
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours.
			return false;
		}
	}//end isAssessment()

	/**
	 * The object being written: the new state on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()

	/**
	 * A string value, trimmed, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
