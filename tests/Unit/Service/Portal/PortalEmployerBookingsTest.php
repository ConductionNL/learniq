<?php

/**
 * An employer books places, names a participant and supplies a birth date,
 * only ever for her own company.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\EmployerBookingProjection;
use OCA\Learniq\Service\Portal\PortalEmployerBookings;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * PortalEmployerBookings over a store that behaves like OpenRegister.
 */
class PortalEmployerBookingsTest extends TestCase {

	private const COMPANY = 'co-jansen';

	private const OTHER = 'co-other';

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	private RegisterFaithfulStore $store;

	/**
	 * The service over a store with Jansen, another company, an exam edition with one session and three people.
	 *
	 * @return PortalEmployerBookings
	 */
	private function bookings(): PortalEmployerBookings {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'client-organisation' => [
				['id' => self::COMPANY, 'name' => 'Jansen Installatietechniek BV', 'locationId' => 'loc-1', 'contactName' => 'Linda Jansen', 'lifecycle' => 'active', 'tenant_id' => self::TENANT],
				['id' => self::OTHER, 'name' => 'Ander BV', 'locationId' => 'loc-1', 'lifecycle' => 'active', 'tenant_id' => self::TENANT],
			],
			'course' => [['id' => 'course-fgas', 'name' => 'F-gassen: herhaling en examen', 'tags' => ['examen']]],
			'cohort' => [
				['id' => 'ed-1', 'name' => 'F-gassen, 22 oktober 2026', 'courseId' => 'course-fgas', 'locationId' => 'loc-1', 'lifecycle' => 'planned', 'teacherIds' => []],
				['id' => 'ed-done', 'name' => 'F-gassen, 1 oktober 2026', 'courseId' => 'course-fgas', 'locationId' => 'loc-1', 'lifecycle' => 'completed'],
				['id' => 'ed-elsewhere', 'name' => 'F-gassen elders', 'courseId' => 'course-fgas', 'locationId' => 'loc-2', 'lifecycle' => 'planned'],
			],
			'session' => [['id' => 's-1', 'cohortId' => 'ed-1', 'startsAt' => '2026-10-22T08:30:00+02:00', 'endsAt' => '2026-10-22T16:30:00+02:00']],
			'learner-profile' => [
				['id' => 'lp-tom', 'ncUserId' => 'tom', 'givenName' => 'Tom', 'familyName' => 'Verbeek', 'birthDate' => '1988-06-12', 'organisationRef' => self::COMPANY],
				['id' => 'lp-youssef', 'ncUserId' => 'youssef', 'givenName' => 'Youssef', 'familyName' => 'El Amrani', 'organisationRef' => self::COMPANY],
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

		$projection = new EmployerBookingProjection(objectService: $objectService, users: $this->createMock(IUserManager::class));

		return new PortalEmployerBookings(objectService: $objectService, projection: $projection, logger: new NullLogger());
	}//end bookings()

	/**
	 * The stored booking.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>
	 */
	private function booking(string $id): array {
		return array_column($this->store->rows['course-booking'], null, 'id')[$id];
	}//end booking()

	/**
	 * Book two places, name both people, then supply the missing birth date: the booking moves from
	 * "two names missing" to "one birth date missing" to received with nothing waiting.
	 *
	 * @return void
	 */
	public function testABookingFromPlacesToCompleteDetails(): void {
		$service = $this->bookings();

		$booked = $service->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-1', 'participantCount' => '2']);
		self::assertSame(201, $booked->status, json_encode($booked->body));
		$id = $booked->body['bookingRef'];
		self::assertSame('Vul de namen van 2 deelnemers in', $this->booking($id)['statusNote']);
		self::assertSame(self::COMPANY, $this->booking($id)['organisationRef']);

		self::assertSame(201, $service->addParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-tom'])->status);
		self::assertSame(201, $service->addParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-youssef'])->status);
		self::assertSame('Geboortedatum van 1 deelnemer ontbreekt', $this->booking($id)['statusNote']);
		self::assertSame('Tom Verbeek, Youssef El Amrani', $this->booking($id)['participantNames']);

		$supplied = $service->supplyBirthDate(organisationRef: self::COMPANY, body: ['learnerRef' => 'lp-youssef', 'birthDate' => '1994-03-14']);
		self::assertSame(200, $supplied->status);
		self::assertSame('1994-03-14', array_column($this->store->rows['learner-profile'], null, 'id')['lp-youssef']['birthDate']);
		self::assertSame('received', $this->booking($id)['employerStatus']);
		self::assertSame(0, $this->booking($id)['missingDetailsCount']);
		// The answer never carries the date back.
		self::assertArrayNotHasKey('birthDate', $supplied->body);
	}//end testABookingFromPlacesToCompleteDetails()

	/**
	 * An edition of another location, a past edition, or a number out of range is refused.
	 *
	 * @return void
	 */
	public function testOnlyThisCompanysOpenEditionsCanBeBooked(): void {
		$service = $this->bookings();

		self::assertSame(404, $service->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-elsewhere', 'participantCount' => 1])->status);
		self::assertSame(404, $service->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-done', 'participantCount' => 1])->status);
		self::assertSame(422, $service->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-1', 'participantCount' => 13])->status);
		self::assertSame(403, $service->book(organisationRef: 'co-unknown', body: ['cohortId' => 'ed-1', 'participantCount' => 1])->status);
		self::assertSame([], $this->store->rows['course-booking']);
	}//end testOnlyThisCompanysOpenEditionsCanBeBooked()

	/**
	 * Another company's person or booking is refused, as is a person twice or a place too many.
	 *
	 * @return void
	 */
	public function testAPlaceGoesOnlyToHerOwnPeopleAndOnlyOnce(): void {
		$service = $this->bookings();
		$id = $service->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-1', 'participantCount' => 1])->body['bookingRef'];

		self::assertSame(404, $service->addParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-stranger'])->status);
		self::assertSame(404, $service->addParticipant(organisationRef: self::OTHER, body: ['bookingRef' => $id, 'learnerRef' => 'lp-stranger'])->status);
		self::assertSame(201, $service->addParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-tom'])->status);
		self::assertSame(409, $service->addParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-tom'])->status);
		self::assertSame(409, $service->addParticipant(organisationRef: self::COMPANY, body: ['bookingRef' => $id, 'learnerRef' => 'lp-youssef'])->status);
		self::assertCount(1, $this->store->rows['enrolment']);
	}//end testAPlaceGoesOnlyToHerOwnPeopleAndOnlyOnce()

	/**
	 * A birth date for someone else's employee, one the institute already holds, or one that is no real date is refused.
	 *
	 * @return void
	 */
	public function testABirthDateIsWrittenOnceAndOnlyForHerOwnPeople(): void {
		$service = $this->bookings();

		self::assertSame(404, $service->supplyBirthDate(organisationRef: self::COMPANY, body: ['learnerRef' => 'lp-stranger', 'birthDate' => '1990-01-01'])->status);
		self::assertSame(409, $service->supplyBirthDate(organisationRef: self::COMPANY, body: ['learnerRef' => 'lp-tom', 'birthDate' => '1990-01-01'])->status);
		self::assertSame(422, $service->supplyBirthDate(organisationRef: self::COMPANY, body: ['learnerRef' => 'lp-youssef', 'birthDate' => '14-03-1994'])->status);
		self::assertSame(422, $service->supplyBirthDate(organisationRef: self::COMPANY, body: ['learnerRef' => 'lp-youssef', 'birthDate' => '2026-02-30'])->status);
		self::assertSame('1988-06-12', array_column($this->store->rows['learner-profile'], null, 'id')['lp-tom']['birthDate']);
		self::assertArrayNotHasKey('birthDate', array_column($this->store->rows['learner-profile'], null, 'id')['lp-youssef']);
	}//end testABirthDateIsWrittenOnceAndOnlyForHerOwnPeople()
}//end class
