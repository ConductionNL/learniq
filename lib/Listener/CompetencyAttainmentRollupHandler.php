<?php

/**
 * Learniq Competency Attainment Rollup Handler
 *
 * IEventListener registered against OR's ObjectCreatedEvent (narrowed to
 * WerkprocesAssessment) and ObjectTransitionedEvent. It recognises the three
 * moments the competency roll-up owes work and queues them; the work itself
 * runs in CompetencyAttainmentRollupJob through CompetencyAttainmentRollup
 * (gate 61, ADR-078: post-event work is deferred out of the save path):
 *
 * - WerkprocesAssessment created: resolve its competencyId server-side.
 * - GradeEntry published: roll competency-aligned evidence into
 *   CompetencyAttainment.
 * - WerkprocesAssessment confirmed: roll the assessment into
 *   CompetencyAttainment.
 *
 * ADR-031 legitimate exception: cross-schema event-to-object-write bridge
 * that cannot be expressed as a schema declaration alone. Never a TimedJob
 * (ADR-022): the deferral is a one-off job per event, not a schedule.
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
 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 * @spec openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\CompetencyAttainmentRollupJob;
use OCA\Learniq\Service\CompetencyAttainmentRollup;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Queues the competency roll-up for the events that owe one.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
 */
class CompetencyAttainmentRollupHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';
	private const WERKPROCES_SCHEMA = 'werkproces-assessment';

	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral Queues the work with the acting user.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the created object's register and schema.
	 */
	public function __construct(
		private readonly ListenerDeferralService $deferral,
		private readonly ListenerSchemaResolver $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister created or transitioned event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === true) {
			$this->handleObjectCreated(event: $event);
			return;
		}

		if ($event instanceof ObjectTransitionedEvent === true) {
			$this->handleObjectTransitioned(event: $event);
		}
	}//end handle()

	/**
	 * A WerkprocesAssessment was created: queue its competencyId resolution.
	 *
	 * @param ObjectCreatedEvent $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
	 */
	private function handleObjectCreated(ObjectCreatedEvent $event): void {
		$entity = $event->getObject();
		if ($this->schemaResolver->registerSlug(entity: $entity) !== self::LEARNIQ_REGISTER
			|| $this->schemaResolver->schemaSlug(entity: $entity) !== self::WERKPROCES_SCHEMA
		) {
			return;
		}

		$this->queue(kind: CompetencyAttainmentRollup::WERKPROCES_CREATED, object: $entity->jsonSerialize());
	}//end handleObjectCreated()

	/**
	 * A GradeEntry was published or a WerkprocesAssessment confirmed: queue the roll-up.
	 *
	 * @param ObjectTransitionedEvent $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
	 */
	private function handleObjectTransitioned(ObjectTransitionedEvent $event): void {
		if ($event->getRegister() !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($event->getSchema() === self::GRADE_ENTRY_SCHEMA && $event->getTo() === 'published') {
			$this->queue(kind: CompetencyAttainmentRollup::GRADE_ENTRY_PUBLISHED, object: $event->getObject()->jsonSerialize());
			return;
		}

		if ($event->getSchema() === self::WERKPROCES_SCHEMA && $event->getTo() === 'confirmed') {
			$this->queue(kind: CompetencyAttainmentRollup::WERKPROCES_CONFIRMED, object: $event->getObject()->jsonSerialize());
		}
	}//end handleObjectTransitioned()

	/**
	 * Queue one roll-up, deduplicated per kind and object.
	 *
	 * @param string $kind What happened.
	 * @param array<string, mixed> $object The object as saved.
	 *
	 * @return void
	 */
	private function queue(string $kind, array $object): void {
		// Deduplicated per kind and object: a repeated event for the same row
		// owes one roll-up, which is idempotent anyway. No id, no dedupe.
		$id = (string)($object['id'] ?? ($object['uuid'] ?? ''));
		$dedupeKey = null;
		if ($id !== '') {
			$dedupeKey = $kind . '|' . $id;
		}

		$this->deferral->defer(
			jobClass: CompetencyAttainmentRollupJob::class,
			entry: ['kind' => $kind, 'object' => $object],
			dedupeKey: $dedupeKey
		);
	}//end queue()
}//end class
