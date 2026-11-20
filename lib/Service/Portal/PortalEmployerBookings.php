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
use OCA\Learniq\Kernel\Contracts\PortalEmployerBookingsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks and stores an employer's bookings, participants and birth dates.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */
class PortalEmployerBookings implements PortalEmployerBookingsInterface {

	private const REGISTER = 'learniq';

	/**
	 * The most places one booking may hold; more is an incompany day.
	 */
	public const MAX_PLACES = 12;

	/**
	 * The withdrawal reason of a participant whose employer sent a colleague instead.
	 *
	 * @var string
	 */
	public const SUBSTITUTED = 'vervangen door collega';

	/**
	 * Constructor.
	 *
	 * @param ObjectService             $objectService Writes the rows.
	 * @param EmployerBookingProjection $projection    Reads rows and re-derives a booking.
	 * @param LoggerInterface           $logger        PSR logger.
	 * @param ITimeFactory|null          $time          The clock the substitution deadline is read against; the system clock when absent.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EmployerBookingProjection $projection,
		private readonly LoggerInterface $logger,
		private readonly ?ITimeFactory $time = null,
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
		$answers = new PortalAnswers();
		$cohortId = $answers->text(field: 'cohortId', value: ($body['cohortId'] ?? null));
		$places = $answers->count(field: 'participantCount', value: ($body['participantCount'] ?? null), min: 1, max: self::MAX_PLACES);
		if ($cohortId === '' || $places === null) {
			return $answers->refused(error: 'incomplete', reason: 'incomplete');
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

				$saved = $this->inCompanyOrganisation(company: $company, write: fn (): array => $this->save(
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
				));
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
		$answers = new PortalAnswers();
		$bookingId = $answers->text(field: 'bookingRef', value: ($body['bookingRef'] ?? null));
		$learnerRef = $answers->text(field: 'learnerRef', value: ($body['learnerRef'] ?? null));
		if ($bookingId === '' || $learnerRef === '') {
			return $answers->refused(error: 'incomplete', reason: 'incomplete');
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
		$answers = new PortalAnswers();
		$learnerRef = $answers->text(field: 'learnerRef', value: ($body['learnerRef'] ?? null));
		$birthDate = $this->answeredBirthDate(answers: $answers, value: ($body['birthDate'] ?? null));
		if ($learnerRef === '' || $birthDate === null) {
			return $answers->refused(error: 'birth_date_invalid', reason: 'birth-date-invalid');
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
	 * Send one of the company's people in place of a participant, before the deadline.
	 *
	 * The booking keeps its place: the replaced participant's enrolment is
	 * withdrawn with the reason in words, and the substitute's created. After
	 * the deadline the request is refused with the deadline in words.
	 *
	 * @param string               $organisationRef The company, from the claim.
	 * @param array<string, mixed> $body            `bookingRef` and `learnerRef` (replaced), or the replaced
	 *                                              participant's `enrolmentId` (the row action on her
	 *                                              participants list), and `substituteRef` (sent instead).
	 *
	 * @return PortalOutcome 200 with the booking, or 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-substitutes-a-colleague/specs/portal-contribution/spec.md#requirement-an-employer-sends-a-colleague-in-place-of-a-participant-before-the-deadline
	 */
	public function substituteParticipant(string $organisationRef, array $body): PortalOutcome {
		$substituteRef = $this->text(value: ($body['substituteRef'] ?? null));
		if ($substituteRef === '' || ($this->text(value: ($body['enrolmentId'] ?? null)) === ''
			&& ($this->text(value: ($body['bookingRef'] ?? null)) === '' || $this->text(value: ($body['learnerRef'] ?? null)) === ''))
		) {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		return $this->guarded(
			work: function () use ($organisationRef, $body, $substituteRef): PortalOutcome {
				$refs = $this->substitutionRefs(organisationRef: $organisationRef, body: $body);
				if ($refs instanceof PortalOutcome) {
					return $refs;
				}

				[$bookingId, $learnerRef] = $refs;
				if ($learnerRef === $substituteRef) {
					return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
				}

				$booking = $this->projection->one(schema: 'course-booking', id: $bookingId);
				$substitute = $this->projection->one(schema: 'learner-profile', id: $substituteRef);
				if ($booking === null || ($booking['organisationRef'] ?? null) !== $organisationRef
					|| $substitute === null || ($substitute['organisationRef'] ?? null) !== $organisationRef
				) {
					return new PortalOutcome(status: 404, body: ['error' => 'not_found'], reason: 'not-yours');
				}

				$replaced = $this->substitutionRefusal(booking: $booking, refs: ['learner' => $learnerRef, 'substitute' => $substituteRef]);
				if ($replaced instanceof PortalOutcome) {
					return $replaced;
				}

				$this->swap(replaced: $replaced, substitute: $substitute);
				$booking = ($this->projection->project(bookingId: $bookingId) ?? $booking);

				return new PortalOutcome(status: 200, body: $this->bookingBody(booking: $booking));
			}
		);
	}//end substituteParticipant()

	/**
	 * The booking and the replaced participant a substitution names: given
	 * as `bookingRef` and `learnerRef`, or read from her own company's
	 * enrolment the row action stamps as `enrolmentId`.
	 *
	 * @param string               $organisationRef The company, from the claim.
	 * @param array<string, mixed> $body            The request.
	 *
	 * @return PortalOutcome|array{0: string, 1: string} The refusal, or the booking id and the replaced participant.
	 *
	 * @spec openspec/changes/employer-substitutes-a-colleague/tasks.md#T4
	 */
	private function substitutionRefs(string $organisationRef, array $body): PortalOutcome|array {
		$bookingId = $this->text(value: ($body['bookingRef'] ?? null));
		$learnerRef = $this->text(value: ($body['learnerRef'] ?? null));
		if ($bookingId !== '' && $learnerRef !== '') {
			return [$bookingId, $learnerRef];
		}

		$enrolment = $this->projection->one(schema: 'enrolment', id: $this->text(value: ($body['enrolmentId'] ?? null)));
		if ($enrolment === null || ($enrolment['organisationRef'] ?? null) !== $organisationRef) {
			return new PortalOutcome(status: 404, body: ['error' => 'not_found'], reason: 'not-yours');
		}

		$bookingId = (string)($enrolment['bookingRef'] ?? '');
		$learnerRef = (string)($enrolment['learnerRef'] ?? '');
		if ($bookingId === '' || $learnerRef === '') {
			return new PortalOutcome(status: 404, body: ['error' => 'not_on_booking'], reason: 'not-on-booking');
		}

		return [$bookingId, $learnerRef];
	}//end substitutionRefs()

	/**
	 * Why a substitution cannot go ahead, or the enrolment it replaces.
	 *
	 * @param array<string, mixed>  $booking The booking.
	 * @param array<string, string> $refs    `learner`, the participant to replace, and `substitute`, the colleague sent instead.
	 *
	 * @return PortalOutcome|array<string, mixed> The refusal, or the replaced enrolment.
	 */
	private function substitutionRefusal(array $booking, array $refs): PortalOutcome|array {
		if (in_array(($booking['lifecycle'] ?? 'received'), ['received', 'confirmed'], true) === false) {
			return new PortalOutcome(status: 409, body: ['error' => 'booking_closed'], reason: 'booking-closed');
		}

		$replaced = null;
		foreach ($this->projection->many(schema: 'enrolment', filters: ['bookingRef' => (string)($booking['id'] ?? '')]) as $enrolment) {
			if (($enrolment['lifecycle'] ?? '') === 'withdrawn') {
				continue;
			}

			if (($enrolment['learnerRef'] ?? null) === $refs['substitute']) {
				return new PortalOutcome(status: 409, body: ['error' => 'already_on_booking'], reason: 'already-on-booking');
			}

			if (($enrolment['learnerRef'] ?? null) === $refs['learner']) {
				$replaced = $enrolment;
			}
		}

		if ($replaced === null) {
			return new PortalOutcome(status: 404, body: ['error' => 'not_on_booking'], reason: 'not-on-booking');
		}

		$cohort = ($this->projection->one(schema: 'cohort', id: (string)($booking['cohortId'] ?? '')) ?? []);
		$late = SubstitutionDeadline::refusal(
			deadline: SubstitutionDeadline::moment(cohort: $cohort, firstDay: ($booking['firstDay'] ?? null)),
			now: $this->now()
		);
		if ($late !== null) {
			return new PortalOutcome(status: 409, body: ['error' => 'too_late', 'message' => $late], reason: 'too-late');
		}

		return $replaced;
	}//end substitutionRefusal()

	/**
	 * Withdraw the replaced participant's enrolment and enrol the substitute on the same booking.
	 *
	 * The substitute is enrolled first: a withdrawn enrolment cannot be put
	 * back, so when the substitute's enrolment cannot be written the replaced
	 * participant stays enrolled and the request can be sent again.
	 *
	 * @param array<string, mixed> $replaced   The replaced participant's enrolment.
	 * @param array<string, mixed> $substitute The substitute's learner profile.
	 *
	 * @return void
	 */
	private function swap(array $replaced, array $substitute): void {
		$enrolment = $replaced;
		unset($enrolment['@self'], $enrolment['id'], $enrolment['uuid'], $enrolment['withdrawReason']);
		$this->save(
			schema: 'enrolment',
			row: array_merge(
				$enrolment,
				[
					'learnerId' => (string)($substitute['ncUserId'] ?? ''),
					'learnerRef' => (string)($substitute['id'] ?? ''),
					'lifecycle' => ($replaced['lifecycle'] ?? 'pending'),
					'requestedAt' => $this->now()->format(DATE_ATOM),
				]
			)
		);

		$withdrawn = $replaced;
		unset($withdrawn['@self']);
		$withdrawn['lifecycle'] = 'withdrawn';
		$withdrawn['withdrawReason'] = self::SUBSTITUTED;
		$this->save(schema: 'enrolment', row: $withdrawn);
	}//end swap()

	/**
	 * The moment of the request.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		$zone = new DateTimeZone(EmployerBookingFacts::ZONE);
		if ($this->time === null) {
			return new DateTimeImmutable('now', $zone);
		}

		return (new DateTimeImmutable('@'.$this->time->getTime()))->setTimezone($zone);
	}//end now()

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
	 * The form's birth date if it is one, else null with the answer refused
	 * as not a `date`.
	 *
	 * @param PortalAnswers $answers The form's answers.
	 * @param mixed         $value   What the form sent.
	 *
	 * @return string|null `yyyy-mm-dd`.
	 */
	private function answeredBirthDate(PortalAnswers $answers, mixed $value): ?string {
		$birthDate = $this->birthDate(value: $answers->text(field: 'birthDate', value: $value));
		if ($birthDate === null) {
			$answers->wrongKind(field: 'birthDate', kind: 'date');
		}

		return $birthDate;
	}//end answeredBirthDate()

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
	 * Run a write acting for the company's OpenRegister organisation, so a
	 * row created without a user gets the company's organisation and not the
	 * default one. A company without an organisation writes as before.
	 *
	 * @param array<string, mixed>        $company The company row, with its `@self`.
	 * @param callable(): array<string, mixed> $write   The write.
	 *
	 * @return array<string, mixed> What the write answered.
	 *
	 * @throws \Throwable When OpenRegister cannot be written, or the organisation does not exist.
	 *
	 * @spec openspec/changes/system-saved-bookings-get-the-employer-organisation/specs/portal-contribution/spec.md#requirement-a-booking-an-employer-makes-belongs-to-the-companys-organisation
	 */
	private function inCompanyOrganisation(array $company, callable $write): array {
		$organisation = (string)($company['@self']['organisation'] ?? '');
		if ($organisation === '') {
			return $write();
		}

		return $this->objectService->runForOrganisation(organisationUuid: $organisation, operation: $write);
	}//end inCompanyOrganisation()

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
		$saved = $this->objectService->saveObject(
			object: $row,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: $schema)),
			_rbac: false,
			_multitenancy: false
		);

		return (array)$saved->jsonSerialize();
	}//end save()
}//end class
