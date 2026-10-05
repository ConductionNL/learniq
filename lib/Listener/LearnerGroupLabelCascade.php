<?php

/**
 * Learniq LearnerGroupLabelCascade
 *
 * When a pupil's enrolment is created, moves to another group, changes state
 * or gets a renamed group, queues LearnerGroupLabelRestampJob, which writes
 * the pupil's group line on the learner profile again. The write runs after
 * the request (ADR-078), never inside the enrolment's own save.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\LearnerGroupLabelRestampJob;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Queues the group-line re-stamp of a pupil whose enrolment changed.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
 */
class LearnerGroupLabelCascade implements IEventListener {

	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * The enrolment fields the group line depends on.
	 */
	private const WATCHED = ['cohortId', 'cohortName', 'lifecycle', 'learnerRef'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver  $schemaResolver Entity schema id to slug.
	 * @param ListenerDeferralService $deferral       Queues the re-stamp for after the request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ListenerDeferralService $deferral,
	) {
	}//end __construct()

	/**
	 * Queue the re-stamp when an enrolment the line depends on changed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false && $event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$entity = $event->getObject();
		$old    = [];
		if ($event instanceof ObjectUpdatedEvent === true) {
			$entity = $event->getNewObject();
			$old    = ($event->getOldObject()?->getObject() ?? []);
		}

		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $entity) !== self::ENROLMENT_SCHEMA) {
				return;
			}
		} catch (Throwable $exception) {
			return;
		}

		$new = ($entity->getObject() ?? []);
		$ref = ($new['learnerRef'] ?? null);
		if (is_string($ref) === false || $ref === '' || ($old !== [] && self::unchanged(old: $old, new: $new) === true)) {
			return;
		}

		$this->deferral->defer(
			jobClass: LearnerGroupLabelRestampJob::class,
			entry: ['learnerRef' => $ref],
			dedupeKey: 'learner-group-label:' . $ref
		);
	}//end handle()

	/**
	 * Whether none of the watched fields moved.
	 *
	 * @param array<string, mixed> $old The enrolment before the save.
	 * @param array<string, mixed> $new The enrolment after the save.
	 *
	 * @return bool
	 */
	private static function unchanged(array $old, array $new): bool {
		foreach (self::WATCHED as $field) {
			if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
				return false;
			}
		}

		return true;
	}//end unchanged()
}//end class
