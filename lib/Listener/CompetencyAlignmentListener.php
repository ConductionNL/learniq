<?php

/**
 * Learniq Competency Alignment Listener
 *
 * Pre-save listener (OpenRegister ObjectCreatingEvent and ObjectUpdatingEvent)
 * for the four schemas that align to goals: Lesson, Course, Assignment and
 * Assessment (slug `exam`). It keeps the flat `competencyIds` derived from the
 * structured `competencyAlignments` (goal-alignment-depth), and refuses an
 * alignment list that names an unknown goal, names a goal twice, or carries a
 * depth the goal's own framework does not declare.
 *
 * Sync rules (all in CompetencyAlignmentNormaliser):
 *   - alignments changed      -> competencyIds = their goal ids;
 *   - only competencyIds changed on a row with alignments -> alignments follow;
 *   - both changed            -> alignments win;
 *   - a row that never had alignments keeps competencyIds as written.
 * A key the payload does not carry counts as unchanged, so a partial write
 * never clears either list.
 *
 * ADR-031 exception: a guard plus a nested-to-flat derivation that a schema
 * calculation cannot express. Registered directly (not through
 * ObjectEventSubscription), because its shared proxy does not consult
 * isPropagationStopped() between subscriptions.
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
 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ObjectRowReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps competencyIds derived from competencyAlignments and refuses bad alignments.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
 */
class CompetencyAlignmentListener implements IEventListener {

	/**
	 * Schema slugs that carry competencyAlignments. Assessment's slug is `exam`.
	 */
	private const ALIGNED_SCHEMAS = ['lesson', 'course', 'assignment', 'exam'];

	/**
	 * Schema slugs of the goal and its framework.
	 */
	private const COMPETENCY_SCHEMA = 'competency';
	private const FRAMEWORK_SCHEMA  = 'competency-framework';

	/**
	 * Framework level ids resolved during this request, by framework id.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $levelsByFramework = [];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver        $schemaResolver Resolves the event entity's schema slug.
	 * @param ObjectRowReader               $reader         Reads Competency and CompetencyFramework rows.
	 * @param CompetencyAlignmentNormaliser $normaliser     The list rules.
	 * @param LoggerInterface               $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectRowReader $reader,
		private readonly CompetencyAlignmentNormaliser $normaliser,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister creating or updating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
	 */
	public function handle(Event $event): void {
		$write = $this->unpack(event: $event);
		if ($write === null || $this->isAlignedSchema(entity: $write['entity']) === false) {
			return;
		}

		$new    = ($write['entity']->getObject() ?? []);
		$oldAl  = $this->normaliser->normalise(raw: ($write['old']['competencyAlignments'] ?? []));
		$oldIds = $this->normaliser->flatIds(raw: ($write['old']['competencyIds'] ?? []));
		$newAl  = $this->submittedAlignments(new: $new, stored: $oldAl);
		$newIds = $this->submittedIds(new: $new, stored: $oldIds);

		if ($newAl !== $oldAl) {
			$this->applyChangedAlignments(event: $write['event'], alignments: $newAl, currentIds: $newIds);
			return;
		}

		if ($oldAl !== [] && $newIds !== $oldIds) {
			$this->merge(
				event: $write['event'],
				data: ['competencyAlignments' => $this->normaliser->alignmentsFollowIds(alignments: $oldAl, ids: $newIds)]
			);
		}
	}//end handle()

	/**
	 * The typed event, the object being written and the stored data, or null
	 * for any other event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{event: ObjectCreatingEvent|ObjectUpdatingEvent, entity: ObjectEntity, old: array<string, mixed>}|null
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
	 */
	private function unpack(Event $event): ?array {
		if ($event instanceof ObjectCreatingEvent === true) {
			return ['event' => $event, 'entity' => $event->getObject(), 'old' => []];
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			return [
				'event'  => $event,
				'entity' => $event->getNewObject(),
				'old'    => ($event->getOldObject()?->getObject() ?? []),
			];
		}

		return null;
	}//end unpack()

	/**
	 * The submitted alignments, or the stored ones when the payload does not
	 * carry the key (a partial write never clears the list).
	 *
	 * @param array<string, mixed>                                        $new    The object as submitted.
	 * @param array<int, array{competencyId: string, depth: string|null}> $stored The stored alignments.
	 *
	 * @return array<int, array{competencyId: string, depth: string|null}>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
	 */
	private function submittedAlignments(array $new, array $stored): array {
		if (array_key_exists('competencyAlignments', $new) === false) {
			return $stored;
		}

		return $this->normaliser->normalise(raw: $new['competencyAlignments']);
	}//end submittedAlignments()

	/**
	 * The submitted flat goal list, or the stored one when the payload does
	 * not carry the key.
	 *
	 * @param array<string, mixed> $new    The object as submitted.
	 * @param array<int, string>   $stored The stored flat list.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
	 */
	private function submittedIds(array $new, array $stored): array {
		if (array_key_exists('competencyIds', $new) === false) {
			return $stored;
		}

		return $this->normaliser->flatIds(raw: $new['competencyIds']);
	}//end submittedIds()

	/**
	 * Validate changed alignments, then derive competencyIds from them, or
	 * refuse the write.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent                     $event      The event.
	 * @param array<int, array{competencyId: string, depth: string|null}> $alignments The new alignments.
	 * @param array<int, string>                                          $currentIds The flat list as submitted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-a-depth-the-goals-framework-does-not-know-is-refused
	 */
	private function applyChangedAlignments(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		array $alignments,
		array $currentIds
	): void {
		if ($alignments !== []) {
			$problem = $this->normaliser->problem(alignments: $alignments, goals: $this->resolveGoals(alignments: $alignments));
			if ($problem !== null) {
				$event->setErrors(['reason' => 'competency-alignment-invalid', 'message' => $problem]);
				$event->stopPropagation();
				$this->logger->info('[CompetencyAlignmentListener] Refused an alignment list: {problem}', ['problem' => $problem]);
				return;
			}
		}

		$derived = $this->normaliser->idsFromAlignments(alignments: $alignments);
		if ($derived !== $currentIds) {
			$this->merge(event: $event, data: ['competencyIds' => $derived]);
		}
	}//end applyChangedAlignments()

	/**
	 * Resolve every aligned goal to its code and its framework's level ids.
	 * A goal that does not resolve is left out, which problem() reports.
	 *
	 * @param array<int, array{competencyId: string, depth: string|null}> $alignments The alignments.
	 *
	 * @return array<string, array{code: string, levels: array<int, string>}>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-a-depth-the-goals-framework-does-not-know-is-refused
	 */
	private function resolveGoals(array $alignments): array {
		$goals = [];
		foreach ($this->normaliser->idsFromAlignments(alignments: $alignments) as $goalId) {
			$goal = $this->reader->load(schema: self::COMPETENCY_SCHEMA, id: $goalId);
			if ($goal === null) {
				continue;
			}

			$code = $goal['code'] ?? $goalId;
			if (is_string($code) === false || $code === '') {
				$code = $goalId;
			}

			$goals[$goalId] = [
				'code'   => $code,
				'levels' => $this->frameworkLevels(frameworkId: (string) ($goal['frameworkId'] ?? '')),
			];
		}

		return $goals;
	}//end resolveGoals()

	/**
	 * The level ids a framework declares, cached for this request.
	 *
	 * @param string $frameworkId UUID of the CompetencyFramework.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-a-depth-the-goals-framework-does-not-know-is-refused
	 */
	private function frameworkLevels(string $frameworkId): array {
		if (isset($this->levelsByFramework[$frameworkId]) === true) {
			return $this->levelsByFramework[$frameworkId];
		}

		$levels    = [];
		$framework = $this->reader->load(schema: self::FRAMEWORK_SCHEMA, id: $frameworkId);
		foreach (($framework['proficiencyLevels'] ?? []) as $level) {
			if (is_array($level) === true && is_string($level['levelId'] ?? null) === true) {
				$levels[] = $level['levelId'];
			}
		}

		$this->levelsByFramework[$frameworkId] = $levels;
		return $levels;
	}//end frameworkLevels()

	/**
	 * Whether the entity is one of the four aligned schemas in this app's
	 * register. Not knowing the schema is not knowing it is ours: never
	 * break another app's write.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
	 */
	private function isAlignedSchema(ObjectEntity $entity): bool {
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			$this->logger->debug('[CompetencyAlignmentListener] Schema not resolvable: {msg}', ['msg' => $exception->getMessage()]);
			return false;
		}

		return in_array(needle: $slug, haystack: self::ALIGNED_SCHEMAS, strict: true);
	}//end isAlignedSchema()

	/**
	 * Merge data into the event's modified data, keeping what other
	 * listeners already set.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 * @param array<string, mixed>                    $data  The keys to write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-competencyids-stays-derived-from-the-alignments
	 */
	private function merge(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $data): void {
		$event->setModifiedData(array_merge($event->getModifiedData(), $data));
	}//end merge()
}//end class
