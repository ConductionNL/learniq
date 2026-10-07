<?php

/**
 * Learniq PortalEmployerBookings
 *
 * What an employer does in the portal: book places on an edition, name a
 * participant for a place, and supply a participant's missing birth date.
 * The company is always the one portaliq resolved from her account's claim
 * (`organisationRef`), never a value the form sent, and every row she names
 * must belong to that company (employer-portal-audience).
 *
 * WHY TWO STEPS TO BOOK. Portaliq's forms have no multi-select, and the
 * board asks for the number of places first ("De namen vult u in de volgende
 * stap in"). So a booking is made for a number of places, and each place gets
 * its participant afterwards, one at a time. A place without a name keeps the
 * booking on "Wacht op u".
 *
 * WHY THE BIRTH DATE IS WRITE-ONLY. The exam institution needs it; the
 * employer supplies it, and never reads it back. Her collections project only
 * whether it is missing (`detailsStatus`), not the date.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks and stores an employer's bookings, participants and birth dates.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */
class PortalEmployerBookings {

	private const REGISTER = 'learniq';

	/**
	 * The most places one booking may hold; more is an incompany day.
	 */
	public const MAX_PLACES = 12;

	/**
	 * Constructor.
	 *
	 * @param ObjectService             $objectService Writes the rows.
	 * @param EmployerBookingProjection $projection    Reads rows and re-derives a booking.
	 * @param LoggerInterface           $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EmployerBookingProjection $projection,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Book a number of places on an edition of the company's location.
	 *
	 * @param string               $organisationRef The company, from the claim.
	 * @param array<string, mixed> $body            `cohortId`, `participantCount`.
	 *
	 * @return PortalOutcome 201 with the booking, or 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function book(string $organisationRef, array $body): PortalOutcome {
		$cohortId = $this->text(value: ($body['cohortId'] ?? null));
		$places = filter_var(($body['participantCount'] ?? null), FILTER_VALIDATE_INT);
		if ($cohortId === '' || $places === false || $places < 1 || $places > self::MAX_PLACES) {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		return $this->guarded(
			work: function () use ($organisationRef, $cohortId, $places): PortalOutcome {
				$company = $this->company(organisationRef: $organisationRef);
				if ($company === null) {
					return new PortalOutcome(status: 403, body: ['error' => 'unknown_company'], reason: 'unknown-company');
				}

				$cohort = $this->projection->one(schema: 'cohort', id: $cohortId);
				if ($cohort === null || ($cohort['locationId'] ?? null) !== ($company['locationId'] ?? '') || ($cohort['lifecycle'] ?? '') !== 'planned') {
					return new PortalOutcome(status: 404, body: ['error' => 'edition_not_bookable'], reason: 'edition-not-bookable');
				}

				$saved = $this->save(
					schema: 'course-booking',
					row: [
						'organisationRef' => $organisationRef,
						'cohortId' => $cohortId,
						'courseId' => ($cohort['courseId'] ?? null),
						'participantCount' => $places,
						'participantRefs' => [],
						'missingDetailsCount' => 0,
						'requestedAt' => (new DateTimeImmutable('now', new DateTimeZone(EmployerBookingFacts::ZONE)))->format(DATE_ATOM),
						'requestedByName' => ($company['contactName'] ?? null),
						'lifecycle' => 'received',
						'tenant_id' => ($company['tenant_id'] ?? null),
					]
				);
				$booking = ($this->projection->project(bookingId: (string)($saved['id'] ?? '')) ?? $saved);

				return new PortalOutcome(status: 201, body: $this->bookingBody(booking: $booking));
			}
		);
	}//end book()

	/**
	 * Name one of the company's people for an open place of its booking.
	 *
	 * @param string               $organisationRef The company, from the claim.
	 * @param array<string, mixed> $body            `bookingRef`, `learnerRef`.
	 *
	 * @return PortalOutcome 201 with the booking, or 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function addParticipant(string $organisationRef, array $body): PortalOutcome {
		$bookingId = $this->text(value: ($body['bookingRef'] ?? null));
		$learnerRef = $this->text(value: ($body['learnerRef'] ?? null));
		if ($bookingId === '' || $learnerRef === '') {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		return $this->guarded(
			work: function () use ($organisationRef, $bookingId, $learnerRef): PortalOutcome {
				$booking = $this->projection->one(schema: 'course-booking', id: $bookingId);
				$profile = $this->projection->one(schema: 'learner-profile', id: $learnerRef);
				if ($booking === null || ($booking['organisationRef'] ?? null) !== $organisationRef
					|| $profile === null || ($profile['organisationRef'] ?? null) !== $organisationRef
				) {
					return new PortalOutcome(status: 404, body: ['error' => 'not_found'], reason: 'not-yours');
				}

				$refusal = $this->placeRefusal(booking: $booking, learnerRef: $learnerRef);
				if ($refusal !== null) {
					return $refusal;
				}

				$cohort = ($this->projection->one(schema: 'cohort', id: (string)($booking['cohortId'] ?? '')) ?? []);
				$this->save(
					schema: 'enrolment',
					row: [
						'learnerId' => (string)($profile['ncUserId'] ?? ''),
						'learnerRef' => $learnerRef,
						'courseId' => ($booking['courseId'] ?? ($cohort['courseId'] ?? null)),
						'cohortId' => ($booking['cohortId'] ?? null),
						'locationId' => ($cohort['locationId'] ?? null),
						'bookingRef' => $bookingId,
						'organisationRef' => $organisationRef,
						'source' => 'hr',
						'mandatory' => false,
						'requestedAt' => (new DateTimeImmutable('now', new DateTimeZone(EmployerBookingFacts::ZONE)))->format(DATE_ATOM),
						'tenant_id' => ($booking['tenant_id'] ?? null),
					]
				);
				$booking = ($this->projection->project(bookingId: $bookingId) ?? $booking);

				return new PortalOutcome(status: 201, body: $this->bookingBody(booking: $booking));
			}
		);
	}//end addParticipant()

	/**
	 * Store a birth date for one of the company's participants.
	 *
	 * @param string               $organisationRef The company, from the claim.
	 * @param array<string, mixed> $body            `learnerRef`, `birthDate` (yyyy-mm-dd).
	 *
	 * @return PortalOutcome 200 `{learnerRef, detailsStatus}`, or 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-supplies-a-missing-birth-date-and-never-reads-it
	 */
	public function supplyBirthDate(string $organisationRef, array $body): PortalOutcome {
		$learnerRef = $this->text(value: ($body['learnerRef'] ?? null));
		$birthDate = $this->birthDate(value: $this->text(value: ($body['birthDate'] ?? null)));
		if ($learnerRef === '' || $birthDate === null) {
			return new PortalOutcome(status: 422, body: ['error' => 'birth_date_invalid'], reason: 'birth-date-invalid');
		}

		return $this->guarded(
			work: function () use ($organisationRef, $learnerRef, $birthDate): PortalOutcome {
				$profile = $this->projection->one(schema: 'learner-profile', id: $learnerRef);
				if ($profile === null || ($profile['organisationRef'] ?? null) !== $organisationRef) {
					return new PortalOutcome(status: 404, body: ['error' => 'not_found'], reason: 'not-yours');
				}

				// A date the institute already holds is never overwritten from the portal.
				if (trim((string)($profile['birthDate'] ?? '')) !== '') {
					return new PortalOutcome(status: 409, body: ['error' => 'already_known'], reason: 'already-known');
				}

				$this->save(schema: 'learner-profile', row: array_merge($profile, ['birthDate' => $birthDate]));
				$bookings = [];
				foreach ($this->projection->many(schema: 'enrolment', filters: ['learnerRef' => $learnerRef]) as $enrolment) {
					$bookingRef = (string)($enrolment['bookingRef'] ?? '');
					if ($bookingRef !== '' && isset($bookings[$bookingRef]) === false) {
						$bookings[$bookingRef] = true;
						$this->projection->project(bookingId: $bookingRef);
					}
				}

				return new PortalOutcome(status: 200, body: ['learnerRef' => $learnerRef, 'detailsStatus' => 'complete']);
			}
		);
	}//end supplyBirthDate()

	/**
	 * Why a person cannot take a place on this booking, or null when they can.
	 *
	 * @param array<string, mixed> $booking    The booking.
	 * @param string               $learnerRef The person.
	 *
	 * @return PortalOutcome|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function placeRefusal(array $booking, string $learnerRef): ?PortalOutcome {
		if (in_array(($booking['lifecycle'] ?? 'received'), ['received', 'confirmed'], true) === false) {
			return new PortalOutcome(status: 409, body: ['error' => 'booking_closed'], reason: 'booking-closed');
		}

		$taken = 0;
		foreach ($this->projection->many(schema: 'enrolment', filters: ['bookingRef' => (string)($booking['id'] ?? '')]) as $enrolment) {
			if (($enrolment['lifecycle'] ?? '') === 'withdrawn') {
				continue;
			}

			if (($enrolment['learnerRef'] ?? null) === $learnerRef) {
				return new PortalOutcome(status: 409, body: ['error' => 'already_on_booking'], reason: 'already-on-booking');
			}

			$taken++;
		}

		if ($taken >= (int)($booking['participantCount'] ?? 0)) {
			return new PortalOutcome(status: 409, body: ['error' => 'no_place_left'], reason: 'no-place-left');
		}

		return null;
	}//end placeRefusal()

	/**
	 * The active company this claim names, or null.
	 *
	 * @param string $organisationRef The claim.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function company(string $organisationRef): ?array {
		$company = $this->projection->one(schema: 'client-organisation', id: $organisationRef);
		if ($company === null || ($company['lifecycle'] ?? 'active') !== 'active') {
			return null;
		}

		return $company;
	}//end company()

	/**
	 * A birth date that is a real day between 100 and 14 years ago, or null.
	 *
	 * @param string $value What the form sent.
	 *
	 * @return string|null `yyyy-mm-dd`.
	 */
	private function birthDate(string $value): ?string {
		$parts = [];
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			return null;
		}

		$date = new DateTimeImmutable($value);
		$today = new DateTimeImmutable('today');
		if ($date > $today->modify('-14 years') || $date < $today->modify('-100 years')) {
			return null;
		}

		return $value;
	}//end birthDate()

	/**
	 * What the employer reads back about a booking.
	 *
	 * @param array<string, mixed> $booking The stored booking.
	 *
	 * @return array<string, mixed>
	 */
	private function bookingBody(array $booking): array {
		return [
			'bookingRef' => (string)($booking['id'] ?? ($booking['uuid'] ?? '')),
			'participantCount' => ($booking['participantCount'] ?? null),
			'employerStatus' => ($booking['employerStatus'] ?? null),
			'statusNote' => ($booking['statusNote'] ?? null),
		];
	}//end bookingBody()

	/**
	 * Run a write, turning a failure into a 502 that leaks nothing.
	 *
	 * @param callable(): PortalOutcome $work The write.
	 *
	 * @return PortalOutcome
	 */
	private function guarded(callable $work): PortalOutcome {
		try {
			return $work();
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalEmployerBookings] An employer write failed: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}
	}//end guarded()

	/**
	 * Store a row; answers it as stored.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When OpenRegister cannot be written.
	 */
	private function save(string $schema, array $row): array {
		$saved = $this->objectService->saveObject(object: $row, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);

		return (array)$saved->jsonSerialize();
	}//end save()

	/**
	 * A string value, trimmed, or ''.
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
