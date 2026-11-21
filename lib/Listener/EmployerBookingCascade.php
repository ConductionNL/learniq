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
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Listener\Support\SavedObjectChange;
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

	use SavedObjectChange;

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
	 * @param ListenerSchemaResolverInterface  $schemaResolver Entity schema id to slug.
	 * @param ListenerDeferralService $deferral       Queues the work for after the request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
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
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public static function entry(string $slug, array $row, bool $created): ?array {
		if ($slug === 'enrolment') {
			$booking = (string)($row['bookingRef'] ?? '');
			if ($booking === '') {
				return null;
			}

			return ['bookingRef' => $booking];
		}

		// A new booking is derived by whoever made it; a new profile is on no booking yet.
		$id = (string)($row['id'] ?? '');
		if ($created === true || $id === '') {
			return null;
		}

		if ($slug === 'course-booking') {
			return ['bookingRef' => $id];
		}

		return ['learnerRef' => $id];
	}//end entry()
}//end class
