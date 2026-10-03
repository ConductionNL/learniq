<?php

/**
 * Learniq Curriculum Coverage Rollup Handler
 *
 * Post-save trigger for CurriculumCoverage (curriculum-coverage-rollup),
 * registered on OpenRegister's ObjectCreatedEvent, ObjectUpdatedEvent and
 * ObjectDeletedEvent through ObjectEventSubscription, narrowed to the learniq
 * register and the schemas lesson, course, assignment, exam (Assessment),
 * competency and competency-framework. It works out which frameworks a write
 * touched and hands each to CurriculumCoverageRollup:
 *
 *   - a lesson, course, assignment or assessment: the frameworks of every goal
 *     in the old and new competencyIds and alignments;
 *   - a goal: its old and new framework;
 *   - a framework: itself.
 *
 * It never throws into the save that triggered it: a failed recompute is
 * logged and the next save of that framework repairs it.
 *
 * ADR-031 legitimate exception: a cross-schema aggregation over a recursive
 * goal tree, mirroring FinalGrade/GradeRollupHandler and
 * CompetencyAttainment/CompetencyAttainmentRollupHandler. Never a TimedJob
 * (ADR-022).
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
 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use OCA\Learniq\Service\CurriculumCoverageRollup;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes the curriculum coverage of the frameworks a write touched.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
 */
class CurriculumCoverageRollupHandler implements IEventListener {

	/**
	 * Schemas that align to goals. Assessment's slug is `exam`.
	 */
	private const ALIGNED_SCHEMAS = ['lesson', 'course', 'assignment', 'exam'];

	private const COMPETENCY_SCHEMA = 'competency';
	private const FRAMEWORK_SCHEMA  = 'competency-framework';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver        $schemaResolver Resolves the entity's schema slug.
	 * @param CurriculumCoverageRollup      $rollup         Recomputes one framework.
	 * @param CompetencyAlignmentNormaliser $normaliser     Reads goal ids off a row.
	 * @param LoggerInterface               $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly CurriculumCoverageRollup $rollup,
		private readonly CompetencyAlignmentNormaliser $normaliser,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a created, updated or deleted object.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
	 */
	public function handle(Event $event): void {
		$write = $this->unpack(event: $event);
		if ($write === null) {
			return;
		}

		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $write['entity']);
			foreach ($this->touchedFrameworks(slug: $slug, entity: $write['entity'], new: $write['new'], old: $write['old']) as $frameworkId) {
				$this->rollup->recompute(frameworkId: $frameworkId);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[CurriculumCoverageRollupHandler] Coverage not recomputed: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
		}
	}//end handle()

	/**
	 * The entity plus its new and old data, or null for any other event. A
	 * delete has only old data.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{entity: ObjectEntity, new: array<string, mixed>, old: array<string, mixed>}|null
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
	 */
	private function unpack(Event $event): ?array {
		if ($event instanceof ObjectCreatedEvent === true) {
			$entity = $event->getObject();
			return ['entity' => $entity, 'new' => ($entity->getObject() ?? []), 'old' => []];
		}

		if ($event instanceof ObjectUpdatedEvent === true) {
			$entity = $event->getObject();
			return [
				'entity' => $entity,
				'new'    => ($entity->getObject() ?? []),
				'old'    => ($event->getOldObject()?->getObject() ?? []),
			];
		}

		if ($event instanceof ObjectDeletedEvent === true) {
			$entity = $event->getObject();
			return ['entity' => $entity, 'new' => [], 'old' => ($entity->getObject() ?? [])];
		}

		return null;
	}//end unpack()

	/**
	 * The frameworks a write touched.
	 *
	 * @param string               $slug   The entity's schema slug.
	 * @param ObjectEntity         $entity The entity.
	 * @param array<string, mixed> $new    Data after the write ([] for a delete).
	 * @param array<string, mixed> $old    Data before the write ([] for a create).
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
	 */
	private function touchedFrameworks(string $slug, ObjectEntity $entity, array $new, array $old): array {
		if (in_array(needle: $slug, haystack: self::ALIGNED_SCHEMAS, strict: true) === true) {
			return $this->rollup->frameworksForGoals(
				goalIds: array_values(array_unique(array_merge($this->goalIds(row: $new), $this->goalIds(row: $old))))
			);
		}

		if ($slug === self::COMPETENCY_SCHEMA) {
			return array_values(
				array_unique(
					array_filter(
						[(string) ($new['frameworkId'] ?? ''), (string) ($old['frameworkId'] ?? '')],
						static fn (string $frameworkId): bool => $frameworkId !== ''
					)
				)
			);
		}

		if ($slug === self::FRAMEWORK_SCHEMA) {
			$frameworkId = (string) ($entity->getUuid() ?? ($new['id'] ?? ($old['id'] ?? '')));
			return array_values(array_filter([$frameworkId], static fn (string $id): bool => $id !== ''));
		}

		return [];
	}//end touchedFrameworks()

	/**
	 * Every goal id a row refers to, flat list and alignments together.
	 *
	 * @param array<string, mixed> $row The row's data.
	 *
	 * @return array<int, string>
	 */
	private function goalIds(array $row): array {
		return array_column($this->normaliser->effectiveAlignments(row: $row), 'competencyId');
	}//end goalIds()
}//end class
