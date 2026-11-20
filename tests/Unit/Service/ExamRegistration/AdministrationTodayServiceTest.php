<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\ExamRegistration;

use DateTime;
use OCA\Learniq\Kernel\Contracts\ExamRegistrationServiceInterface;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\ExamRegistration\AdministrationTodayService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * What the administration's Today reads on Monday 5 October.
 *
 * @covers \OCA\Learniq\Service\ExamRegistration\AdministrationTodayService
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayException
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class AdministrationTodayServiceTest extends TestCase {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The open lists the list service answers with.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $open = [];

	protected function setUp(): void {
		$this->rows = [
			'credential' => [
				[
					'id' => 'k-1',
					'learnerName' => 'Tom Verbeek',
					'courseName' => 'F-gassen',
					'expiresAt' => '2026-11-30T00:00:00+01:00',
					'lifecycle' => 'issued',
					'renewalLine' => 'Herhaling op 8 oktober'
				],
				[
					'id' => 'k-2',
					'learnerName' => 'Sanne de Wit',
					'courseName' => 'F-gassen',
					'expiresAt' => '2026-10-20T00:00:00+02:00',
					'lifecycle' => 'issued',
					'renewalEnrolmentId' => 'e-renew'
				],
				[
					'id' => 'k-3',
					'learnerName' => 'Eva',
					'courseName' => 'BRL 100',
					'expiresAt' => '2026-12-15T00:00:00+01:00',
					'lifecycle' => 'issued'
				],
				// Expires on 1 January, already expired, revoked, never expires.
				['id' => 'k-4', 'learnerName' => 'Jan', 'expiresAt' => '2027-01-01T00:00:00+01:00', 'lifecycle' => 'issued'],
				['id' => 'k-5', 'learnerName' => 'Piet', 'expiresAt' => '2026-09-01T00:00:00+02:00', 'lifecycle' => 'issued'],
				['id' => 'k-6', 'learnerName' => 'Klaas', 'expiresAt' => '2026-11-01T00:00:00+01:00', 'lifecycle' => 'revoked'],
				['id' => 'k-7', 'learnerName' => 'Kees', 'lifecycle' => 'issued'],
			],
			'enrolment' => [['id' => 'e-renew', 'lifecycle' => 'pending', 'firstDay' => '2026-10-15', 'dayLabel' => 'donderdag 15 oktober']],
			'cohort' => [
				['id' => 'c-1', 'name' => 'F-gassen 1 okt', 'dayDate' => '2026-10-01', 'dayStatus' => 'rounding-off'],
				['id' => 'c-0', 'name' => 'BRL 28 sep', 'dayDate' => '2026-09-28', 'dayStatus' => 'rounding-off'],
				['id' => 'c-8', 'name' => 'F-gassen 8 okt', 'dayDate' => '2026-10-08', 'dayStatus' => 'goes-ahead'],
			],
			'exam-registration' => [
				[
					'id' => 'l-8',
					'examDate' => '2026-10-08',
					'courseName' => 'F-gassen',
					'bodyName' => 'Examenbureau',
					'participantCount' => 11,
					'lifecycle' => 'open'
				],
				['id' => 'l-old', 'examDate' => '2026-10-01', 'lifecycle' => 'sent'],
				[
					'id' => 'l-22',
					'examDate' => '2026-10-22',
					'courseName' => 'Hybride',
					'bodyName' => 'Examenbureau',
					'participantCount' => 4,
					'lifecycle' => 'closed'
				],
			],
		];
		$this->open = [
			['id' => 'l-22', 'closesAt' => '2026-10-21T12:00:00+02:00', 'incomplete' => [['sentence' => 'x']]],
			[
				'id' => 'l-8',
				'closesAt' => '2026-10-07T12:00:00+02:00',
				'incomplete' => [['sentence' => 'Van Youssef El Amrani (Jansen Installatietechniek) ontbreekt de geboortedatum.']]
			],
			['id' => 'l-9', 'closesAt' => '2026-10-06T12:00:00+02:00', 'incomplete' => []],
		];
	}//end setUp()

	private function service(): AdministrationTodayService {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema  = $filters['schema'];
				unset($filters['register'], $filters['schema']);

				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => (isset($config['ids']) === false || in_array($row['id'], $config['ids'], true) === true)
							&& array_intersect_assoc($filters, $row) === $filters
					)
				);
			}
		);
		$lists = $this->createMock(originalClassName: ExamRegistrationServiceInterface::class);
		$lists->method('openLists')->willReturnCallback(fn (): array => $this->open);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-04T23:30:00Z'));

		return new AdministrationTodayService(objectService: $objects, time: $time, lists: $lists);
	}//end service()

	public function testTodayOpensOnTheSoonestListWithSomebodyIncomplete(): void {
		$today = $this->service()->today();

		self::assertSame(expected: '2026-10-05', actual: $today['today'], message: 'read in Amsterdam');
		self::assertSame(expected: 'l-8', actual: $today['first']['id'], message: 'l-9 closes sooner but is complete');
		self::assertSame(expected: ['l-9', 'l-8', 'l-22'], actual: array_column($today['registrations'], 'id'));
		self::assertSame(
			expected: ['c-0', 'c-1'],
			actual: array_column($today['toIssue'], 'cohortId'),
			message: 'the course days to issue, oldest first'
		);
		self::assertSame(expected: '2027-01-01', actual: $today['expiring']['before'], message: 'Certificaten die verlopen voor 1 januari');
		self::assertSame(expected: ['k-2', 'k-1', 'k-3'], actual: array_column($today['expiring']['rows'], 'credentialId'));
		self::assertSame(
			expected: ['l-8', 'l-22'],
			actual: array_column($today['comingExams'], 'registrationId'),
			message: 'past exams are not coming'
		);
		self::assertSame(expected: 11, actual: $today['comingExams'][0]['participantCount']);
	}//end testTodayOpensOnTheSoonestListWithSomebodyIncomplete()

	public function testWithoutAnIncompleteListTheSoonestComesFirstAndWithoutListsNothing(): void {
		self::assertSame(
			expected: 'a',
			actual: AdministrationTodayService::firstToday(lists: [['id' => 'a', 'incomplete' => []], ['id' => 'b', 'incomplete' => []]])['id']
		);
		self::assertNull(actual: AdministrationTodayService::firstToday(lists: []));
	}//end testWithoutAnIncompleteListTheSoonestComesFirstAndWithoutListsNothing()

	public function testTheExpiringListNamesTheRenewalBooking(): void {
		$rows = $this->service()->expiring(before: '2026-12-01');

		self::assertSame(expected: ['k-2', 'k-1'], actual: array_column($rows, 'credentialId'));
		self::assertSame(expected: '2026-10-20', actual: $rows[0]['expiresOn']);
		self::assertSame(expected: 'Sanne de Wit', actual: $rows[0]['learnerName']);
		self::assertSame(
			expected: ['enrolmentId' => 'e-renew', 'firstDay' => '2026-10-15', 'dayLabel' => 'donderdag 15 oktober'],
			actual: $rows[0]['renewal']
		);
		self::assertSame(expected: 'Herhaling op donderdag 15 oktober', actual: $rows[0]['renewalLine']);
		self::assertSame(expected: 'Herhaling op 8 oktober', actual: $rows[1]['renewalLine'], message: 'the readable copy wins');
		self::assertNull(actual: $rows[1]['renewal']);

		$this->rows['enrolment'][0]['lifecycle'] = 'withdrawn';
		self::assertSame(
			expected: 'Geen herhaling geboekt',
			actual: $this->service()->expiring(before: '2026-12-01')[0]['renewalLine'],
			message: 'a withdrawn renewal is no booking'
		);

		$this->expectException(exception: CourseDayException::class);
		$this->service()->expiring(before: '1 december');
	}//end testTheExpiringListNamesTheRenewalBooking()
}//end class
