<?php

/**
 * Learniq CourseDayStatusCascade
 *
 * Queues a course day's status for re-derivation when something it depends
 * on moves: an enrolment or a company booking on the day, a session's date,
 * or the day's own minimum, maximum or decision. CourseDayRestampJob does the
 * work after the request, so a save is never slowed down or broken by it.
 *
 * The restamp writes only `dayStatus`, which this listener does not watch,
 * so it never queues itself.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\CourseDayRestampJob;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Listener\Support\SavedObjectChange;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Queues the course days whose facts changed.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
class CourseDayStatusCascade implements IEventListener {

	use SavedObjectChange;

	/**
	 * The fields per schema a course day's status depends on.
	 *
	 * @var array<string, list<string>>
	 */
	public const WATCHED = [
		'enrolment' => ['lifecycle', 'cohortId', 'bookingRef'],
		'course-booking' => ['participantCount', 'cohortId', 'lifecycle'],
		'session' => ['startsAt', 'cohortId'],
		'cohort' => ['minParticipants', 'capacity', 'goDecision', 'courseId'],
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Entity schema id to slug.
	 * @param ListenerDeferralService         $deferral       Queues the work for after the request.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly ListenerDeferralService $deferral,
	) {
	}//end __construct()

	/**
	 * Queue the course days a watched change touches.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false && $event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		[$entity, $old] = self::sides(event: $event);
		$slug = $this->watchedSlug(entity: $entity);
		if ($slug === null) {
			return;
		}

		$new = array_merge(($entity->getObject() ?? []), ['id' => (string)$entity->getUuid()]);
		if ($old !== [] && self::unchanged(fields: self::WATCHED[$slug], old: $old, new: $new) === true) {
			return;
		}

		foreach (self::cohorts(slug: $slug, new: $new, old: $old) as $cohortId) {
			$this->deferral->defer(jobClass: CourseDayRestampJob::class, entry: ['cohortId' => $cohortId], dedupeKey: 'course-day:' . $cohortId);
		}
	}//end handle()

	/**
	 * The entity's schema slug when it is one this cascade watches, else null.
	 *
	 * @param object|null $entity The saved object.
	 *
	 * @return string|null
	 */
	private function watchedSlug(?object $entity): ?string {
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			return null;
		}

		if (isset(self::WATCHED[$slug]) === false) {
			return null;
		}

		return $slug;
	}//end watchedSlug()

	/**
	 * The course days a changed row touches: its day now and, when it moved, its day before.
	 *
	 * @param string               $slug The schema slug.
	 * @param array<string, mixed> $new  After the save.
	 * @param array<string, mixed> $old  Before the save; empty for a new row.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public static function cohorts(string $slug, array $new, array $old): array {
		$sides = [$new['cohortId'] ?? '', $old['cohortId'] ?? ''];
		if ($slug === 'cohort') {
			$sides = [$new['id'] ?? ''];
		}

		return array_values(array_unique(array_filter(array_map('strval', $sides), static fn (string $id): bool => $id !== '')));
	}//end cohorts()
}//end class
