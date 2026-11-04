<?php

/**
 * A booking an employer makes through the portal is created acting for the
 * company's OpenRegister organisation, so it does not land in the default
 * organisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/system-saved-bookings-get-the-employer-organisation/specs/portal-contribution/spec.md
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
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\Portal\PortalEmployerBookings
 * @uses   \OCA\Learniq\Service\Portal\EmployerBookingProjection
 * @uses   \OCA\Learniq\Service\Portal\EmployerBookingFacts
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
 * @uses   \OCA\Learniq\Service\Portal\CourseDayLines
 */
class EmployerBookingOrganisationTest extends TestCase {

	private const COMPANY = 'co-jansen';

	private const COMPANY_ORGANISATION = '11111111-1111-4111-8111-111111111111';

	private RegisterFaithfulStore $store;

	/**
	 * The organisation the current OpenRegister call acts for, or null.
	 *
	 * @var string|null
	 */
	private ?string $actingFor = null;

	/**
	 * Per save: [schema, organisation acted for or null].
	 *
	 * @var array<int, array{0: string, 1: string|null}>
	 */
	private array $saves = [];

	/**
	 * The organisations runForOrganisation() was asked to act for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	public function testTheBookingIsCreatedActingForTheCompanysOrganisation(): void {
		$booked = $this->bookings(companySelf: ['organisation' => self::COMPANY_ORGANISATION])
			->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-1', 'participantCount' => '1']);

		self::assertSame(201, $booked->status, (string)json_encode($booked->body));
		self::assertSame([self::COMPANY_ORGANISATION], $this->asked);
		self::assertSame(['course-booking', self::COMPANY_ORGANISATION], $this->saves[0]);
	}//end testTheBookingIsCreatedActingForTheCompanysOrganisation()

	public function testOnlyTheCreateActsForTheOrganisation(): void {
		$this->bookings(companySelf: ['organisation' => self::COMPANY_ORGANISATION])
			->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-1', 'participantCount' => '1']);

		foreach (array_slice($this->saves, 1) as [$schema, $organisation]) {
			self::assertNull($organisation, 'the follow-up save of '.$schema.' runs as before');
		}
	}//end testOnlyTheCreateActsForTheOrganisation()

	public function testABodyCannotChooseTheBookingsOrganisation(): void {
		$booked = $this->bookings(companySelf: ['organisation' => self::COMPANY_ORGANISATION], companyData: ['organisation' => 'forged-organisation'])->book(
			organisationRef: self::COMPANY,
			body: [
				'cohortId' => 'ed-1',
				'participantCount' => '1',
				'organisation' => 'forged-organisation',
				'@self' => ['organisation' => 'forged-organisation'],
				'tenant_id' => 'forged-tenant',
			]
		);

		self::assertSame(201, $booked->status);
		self::assertSame([self::COMPANY_ORGANISATION], $this->asked);
		self::assertSame(['course-booking', self::COMPANY_ORGANISATION], $this->saves[0]);
		$stored = $this->store->rows['course-booking'][0];
		self::assertArrayNotHasKey('organisation', $stored);
		self::assertNotSame('forged-organisation', ($stored['@self']['organisation'] ?? null));
		self::assertStringNotContainsString('forged-tenant', (string)json_encode($stored));
	}//end testABodyCannotChooseTheBookingsOrganisation()

	public function testACompanyWithoutAnOrganisationBooksAsBefore(): void {
		$booked = $this->bookings(companySelf: null)
			->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-1', 'participantCount' => '1']);

		self::assertSame(201, $booked->status);
		self::assertSame([], $this->asked);
		self::assertSame(['course-booking', null], $this->saves[0]);
	}//end testACompanyWithoutAnOrganisationBooksAsBefore()

	public function testAnOrganisationThatDoesNotExistBooksNothing(): void {
		$booked = $this->bookings(companySelf: ['organisation' => 'gone'])
			->book(organisationRef: self::COMPANY, body: ['cohortId' => 'ed-1', 'participantCount' => '1']);

		self::assertSame(502, $booked->status);
		self::assertSame([], $this->saves);
	}//end testAnOrganisationThatDoesNotExistBooksNothing()

	/**
	 * The service over a store with one company (with or without `@self`)
	 * and one bookable edition, and an object service that acts for an
	 * organisation as OpenRegister's runForOrganisation() does.
	 *
	 * @param array<string, mixed>|null $companySelf The company row's `@self`, or null for none.
	 * @param array<string, mixed>      $companyData More data fields of the company row.
	 *
	 * @return PortalEmployerBookings
	 */
	private function bookings(?array $companySelf, array $companyData = []): PortalEmployerBookings {
		$company = ['id' => self::COMPANY, 'name' => 'Jansen Installatietechniek BV', 'locationId' => 'loc-1', 'contactName' => 'Linda Jansen', 'lifecycle' => 'active'] + $companyData;
		if ($companySelf !== null) {
			$company['@self'] = $companySelf;
		}

		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'client-organisation' => [$company],
			'course' => [['id' => 'course-fgas', 'name' => 'F-gassen', 'tags' => []]],
			'cohort' => [['id' => 'ed-1', 'name' => 'F-gassen, 22 oktober 2026', 'courseId' => 'course-fgas', 'locationId' => 'loc-1', 'lifecycle' => 'planned', 'teacherIds' => []]],
			'session' => [],
			'learner-profile' => [],
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
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): object {
				$this->saves[] = [(string)$schema, $this->actingFor];
				return $this->store->save((string)$schema, $object, $uuid, false);
			}
		);
		$objectService->method('runForOrganisation')->willReturnCallback(
			function (string $organisationUuid, callable $operation): mixed {
				$this->asked[] = $organisationUuid;
				if ($organisationUuid !== self::COMPANY_ORGANISATION) {
					throw new RuntimeException('Cannot act for organisation "'.$organisationUuid.'": it does not exist.');
				}

				$this->actingFor = $organisationUuid;
				try {
					return $operation();
				} finally {
					$this->actingFor = null;
				}
			}
		);

		$projection = new EmployerBookingProjection(objectService: $objectService, users: $this->createMock(IUserManager::class));

		return new PortalEmployerBookings(objectService: $objectService, projection: $projection, logger: new NullLogger());
	}//end bookings()
}//end class
