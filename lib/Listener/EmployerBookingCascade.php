<?php

/**
 * Learniq EmployerBookingCascade
 *
 * Keeps a company's booking right when staff change what it is made of: a
 * participant's enrolment confirmed, withdrawn or moved, a participant's
 * birth date or name filled in in Nextcloud, or the number of places changed.
 * It only queues the booking; EmployerBookingRestampJob re-derives it after
 * the request (ADR-078), so a save is never slowed down or broken by it
 * (employer-portal-audience).
 *
 * The projection's own writes do not queue again: they change only the
 * derived fields, and this listener watches none of them.
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\EmployerBookingRestampJob;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Queues the re-derivation of a booking whose parts changed.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */
class EmployerBookingCascade implements IEventListener {

	/**
	 * The fields per schema a booking depends on.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const WATCHED = [
		'enrolment' => ['lifecycle', 'bookingRef', 'learnerRef'],
		'learner-profile' => ['birthDate', 'givenName', 'familyName'],
		'course-booking' => ['participantCount', 'cohortId'],
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver  $schemaResolver Entity schema id to slug.
	 * @param ListenerDeferralService $deferral       Queues the work for after the request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ListenerDeferralService $deferral,
	) {
	}//end __construct()

	/**
	 * Queue the booking (or the person's bookings) when a watched field moved.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false && $event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		[$entity, $old] = self::sides(event: $event);
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			return;
		}

		if (isset(self::WATCHED[$slug]) === false) {
			return;
		}

		$new = array_merge(($entity->getObject() ?? []), ['id' => (string)$entity->getUuid()]);
		if ($old !== [] && self::unchanged(fields: self::WATCHED[$slug], old: $old, new: $new) === true) {
			return;
		}

		$entry = self::entry(slug: $slug, row: $new, created: $old === []);
		if ($entry === null) {
			return;
		}

		$this->deferral->defer(jobClass: EmployerBookingRestampJob::class, entry: $entry, dedupeKey: 'employer-booking:' . implode(':', $entry));
	}//end handle()

	/**
	 * What to queue for a changed row, or null when it touches no booking.
	 *
	 * @param string               $slug    The schema slug.
	 * @param array<string, mixed> $row     The saved row.
	 * @param bool                 $created Whether the row is new.
	 *
	 * @return array<string, string>|null
	 */
	public static function entry(string $slug, array $row, bool $created): ?array {
		if ($slug === 'enrolment') {
			$booking = (string)($row['bookingRef'] ?? '');
			return $booking === '' ? null : ['bookingRef' => $booking];
		}

		if ($slug === 'course-booking') {
			// A new booking is derived by whoever made it.
			return ($created === true || (string)$row['id'] === '') ? null : ['bookingRef' => (string)$row['id']];
		}

		// A person who is on no booking yet has nothing to re-derive; a new profile is on none.
		return ($created === true || (string)$row['id'] === '') ? null : ['learnerRef' => (string)$row['id']];
	}//end entry()

	/**
	 * The saved object and, for an update, the data before the save.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The event.
	 *
	 * @return array{0: \OCA\OpenRegister\Db\ObjectEntity, 1: array<string, mixed>}
	 */
	private static function sides(ObjectCreatedEvent|ObjectUpdatedEvent $event): array {
		if ($event instanceof ObjectUpdatedEvent === true) {
			return [$event->getNewObject(), ($event->getOldObject()?->getObject() ?? [])];
		}

		return [$event->getObject(), []];
	}//end sides()

	/**
	 * Whether none of the watched fields moved.
	 *
	 * @param array<int, string>   $fields The watched fields.
	 * @param array<string, mixed> $old    Before the save.
	 * @param array<string, mixed> $new    After the save.
	 *
	 * @return bool
	 */
	private static function unchanged(array $fields, array $old, array $new): bool {
		foreach ($fields as $field) {
			if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
				return false;
			}
		}

		return true;
	}//end unchanged()
}//end class
