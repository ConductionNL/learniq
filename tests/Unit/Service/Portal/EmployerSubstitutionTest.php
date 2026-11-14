<?php

/**
 * Unit tests for an employer sending a colleague in a participant's place.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/employer-substitutes-a-colleague/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use DateTimeImmutable;
use OCA\Learniq\Service\Portal\EmployerBookingProjection;
use OCA\Learniq\Service\Portal\PortalEmployerBookings;
use OCA\Learniq\Service\Portal\SubstitutionDeadline;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tom cannot come; Linda sends Bram. The booking keeps its places.
 */
class EmployerSubstitutionTest extends TestCase {

	private const COMPANY = 'co-jansen';

	private const OTHER = 'co-other';

	/**
	 * The register rows.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The service at a given moment, over a booking of Tom and Youssef on Thursday 22 October.
	 *
	 * @param string $now The moment of the request, ISO 8601.
	 *
	 * @return PortalEmployerBookings
	 */
	private function bookings(string $now): PortalEmployerBookings {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'client-organisation' => [
				['id' => self::COMPANY, 'name' => 'Jansen Installatietechniek BV', 'locationId' => 'loc-1', 'lifecycle' => 'active'],
			],
			'course' => [['id' => 'course-fgas', 'name' => 'F-gassen: herhaling en examen']],
			'cohort' => [['id' => 'ed-1', 'name' => 'F-gassen, 22 oktober 2026', 'courseId' => 'course-fgas', 'locationId' => 'loc-1', 'lifecycle' => 'planned', 'teacherIds' => []]],
			'session' => [['id' => 's-1', 'cohortId' => 'ed-1', 'startsAt' => '2026-10-22T08:30:00+02:00', 'endsAt' => '2026-10-22T16:30:00+02:00']],
			'learner-profile' => [
				['id' => 'lp-tom', 'ncUserId' => 'tom', 'givenName' => 'Tom', 'familyName' => 'Verbeek', 'birthDate' => '1988-06-12', 'organisationRef' => self::COMPANY],
				['id' => 'lp-youssef', 'ncUserId' => 'youssef', 'givenName' => 'Youssef', 'familyName' => 'El Amrani', 'birthDate' => '1994-03-14', 'organisationRef' => self::COMPANY],
				['id' => 'lp-bram', 'ncUserId' => 'bram', 'givenName' => 'Bram', 'familyName' => 'de Wit', 'birthDate' => '1990-01-02', 'organisationRef' => self::COMPANY],
				['id' => 'lp-sanne', 'ncUserId' => 'sanne', 'givenName' => 'Sanne', 'familyName' => 'Bos', 'birthDate' => '1992-05-06', 'organisationRef' => self::COMPANY],
				['id' => 'lp-stranger', 'ncUserId' => 'stranger', 'givenName' => 'Piet', 'familyName' => 'Ander', 'organisationRef' => self::OTHER],
			],
			'course-booking' => [],
			'enrolment' => [],
			'credential' => [],
			'vestiging' => [],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, false, false)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): object => $this->store->save((string)$schema, $object, $uuid, false)
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable($now))->getTimestamp());

		$projection = new EmployerBookingProjection(objectService: $objectService, users: $this->createMock(IUserManager::class));

		return new PortalEmployerBookings(objectService: $objectService, projection: $projection, logger: new NullLogger(), time: $time);
	}//end bookings()

	/**
	 * Book two places and name Tom and Youssef.
	 *
	 * @param PortalEmployerBookings $service The service.
	 *
	 * @return string The booking id.
	 */
	private function tomAndYoussef(PortalEmployerBookings $service): string {
		$id = $service->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-1', 'participantCount' => 2])->body['bookingRef'];
		$service->addParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-tom']);
		$service->addParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-youssef']);

		return $id;
	}//end tomAndYoussef()

	/**
	 * The enrolments of a booking, by learner.
	 *
	 * @param string $bookingId The booking.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function enrolments(string $bookingId): array {
		$rows = array_filter($this->store->rows['enrolment'], static fn (array $row): bool => ($row['bookingRef'] ?? null) === $bookingId);

		return array_column($rows, null, 'learnerRef');
	}//end enrolments()

	/**
	 * Bram goes instead of Tom on Monday: the booking keeps two places, Tom is withdrawn with the reason in words.
	 *
	 * @return void
	 */
	public function testBramGoesInsteadOfTom(): void {
		$service = $this->bookings(now: '2026-10-19T10:00:00+02:00');
		$id = $this->tomAndYoussef(service: $service);

		$outcome = $service->substituteParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-tom', 'substituteRef' => 'lp-bram']);

		self::assertSame(200, $outcome->status, (string)json_encode($outcome->body));
		$enrolments = $this->enrolments(bookingId: $id);
		self::assertSame('withdrawn', $enrolments['lp-tom']['lifecycle']);
		self::assertSame('vervangen door collega', $enrolments['lp-tom']['withdrawReason']);
		self::assertSame('bram', $enrolments['lp-bram']['learnerId']);
		self::assertSame($id, $enrolments['lp-bram']['bookingRef']);
		self::assertSame('ed-1', $enrolments['lp-bram']['cohortId']);
		self::assertArrayNotHasKey('withdrawReason', $enrolments['lp-bram']);
		self::assertNotSame('withdrawn', $enrolments['lp-youssef']['lifecycle'] ?? null);
		self::assertSame(2, (int)array_column($this->store->rows['course-booking'], null, 'id')[$id]['participantCount']);
	}//end testBramGoesInsteadOfTom()

	/**
	 * Wednesday 12.30 is too late for a Thursday course day; nothing changes.
	 *
	 * @return void
	 */
	public function testTooLateIsRefusedInWords(): void {
		$service = $this->bookings(now: '2026-10-21T12:30:00+02:00');
		$id = $this->tomAndYoussef(service: $service);

		$outcome = $service->substituteParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-tom', 'substituteRef' => 'lp-bram']);

		self::assertSame(409, $outcome->status);
		self::assertSame('Vervangen kan tot woensdag 12:00 uur. Bel de planning.', $outcome->body['message']);
		self::assertArrayNotHasKey('lp-bram', $this->enrolments(bookingId: $id));
		self::assertNotSame('withdrawn', $this->enrolments(bookingId: $id)['lp-tom']['lifecycle'] ?? null);
	}//end testTooLateIsRefusedInWords()

	/**
	 * Only her own people, only a participant of the booking, never someone already on it.
	 *
	 * @return void
	 */
	public function testOnlyHerOwnPeopleAndOnlyAParticipantOfTheBooking(): void {
		$service = $this->bookings(now: '2026-10-19T10:00:00+02:00');
		$id = $this->tomAndYoussef(service: $service);

		$sub = static fn (string $org, string $old, string $new): int => $service->substituteParticipant(
			organisationRef: $org,
			body: ['bookingRef' => $id, 'learnerRef' => $old, 'substituteRef' => $new]
		)->status;

		self::assertSame(404, $sub(self::COMPANY, 'lp-tom', 'lp-stranger'));
		self::assertSame(404, $sub(self::OTHER, 'lp-tom', 'lp-stranger'));
		self::assertSame(404, $sub(self::COMPANY, 'lp-sanne', 'lp-bram'));
		self::assertSame(409, $sub(self::COMPANY, 'lp-tom', 'lp-youssef'));
		self::assertSame(422, $sub(self::COMPANY, 'lp-tom', 'lp-tom'));
		self::assertSame(422, $service->substituteParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-tom'])->status);
		self::assertArrayNotHasKey('lp-stranger', $this->enrolments(bookingId: $id));
	}//end testOnlyHerOwnPeopleAndOnlyAParticipantOfTheBooking()

	/**
	 * The deadline: the working day before at 12.00, over a weekend, or the course date's own.
	 *
	 * @return void
	 */
	public function testTheDeadline(): void {
		self::assertSame('2026-10-21T12:00:00+02:00', SubstitutionDeadline::moment(cohort: [], firstDay: '2026-10-22')?->format(DATE_ATOM));
		self::assertSame('2026-10-23T12:00:00+02:00', SubstitutionDeadline::moment(cohort: [], firstDay: '2026-10-26')?->format(DATE_ATOM), 'a Monday course day: Friday 12.00');
		self::assertSame(
			'2026-10-20T17:00:00+02:00',
			SubstitutionDeadline::moment(cohort: ['substituteUntil' => '2026-10-20T15:00:00Z'], firstDay: '2026-10-22')?->format(DATE_ATOM)
		);
		self::assertNull(SubstitutionDeadline::moment(cohort: [], firstDay: null));
		self::assertNull(SubstitutionDeadline::refusal(cohort: [], firstDay: '2026-10-22', now: new DateTimeImmutable('2026-10-21T12:00:00+02:00')));
		self::assertSame(
			'Vervangen kan tot vrijdag 12:00 uur. Bel de planning.',
			SubstitutionDeadline::refusal(cohort: [], firstDay: '2026-10-26', now: new DateTimeImmutable('2026-10-24T09:00:00+02:00'))
		);
	}//end testTheDeadline()
}//end class
