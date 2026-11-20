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
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\ExamRegistration;

use DateTime;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\ExamRegistration\ExamRegistrationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The registration list of Thursday 8 October's F-gassen exam, read on Monday 5 October.
 *
 * @covers \OCA\Learniq\Service\ExamRegistration\ExamRegistrationService
 * @uses   \OCA\Learniq\Service\ExamRegistration\ExamRegistrationLines
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayException
 * @uses   \OCA\Learniq\Service\CsvCellSanitizer
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class ExamRegistrationServiceTest extends TestCase {

	/**
	 * Rows per schema, written back by saveObject.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every save as [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $saves = [];

	private string $now = '2026-10-05T07:30:00+02:00';

	protected function setUp(): void {
		$enrolments = [];
		$profiles   = [];
		$names      = [
			'Tom Verbeek',
			'Sanne de Wit',
			'Mehmet Kaya',
			'Lotte Bos',
			'Daan Smit',
			'Eva Mulder',
			'Bram Visser',
			'Noor Bakker',
			'Ruben Jong',
			'Iris Peters'
		];
		foreach ($names as $i => $name) {
			[$given, $family] = explode(' ', $name, 2);
			$profiles[]   = [
				'id' => 'p-' . $i,
				'givenName' => $given,
				'familyName' => $family,
				'birthDate' => '1990-01-0' . ($i % 9 + 1),
				'placeOfBirth' => 'Utrecht',
				'organisationRef' => 'o-jansen'
			];
			$enrolments[] = [
				'id' => 'e-' . $i,
				'cohortId' => 'c-8',
				'learnerRef' => 'p-' . $i,
				'lifecycle' => 'active',
				'organisationRef' => 'o-jansen'
			];
		}

		$profiles[]   = [
			'id' => 'p-youssef',
			'givenName' => 'Youssef',
			'familyName' => 'El Amrani',
			'placeOfBirth' => 'Utrecht',
			'organisationRef' => 'o-jansen'
		];
		$enrolments[] = [
			'id' => 'e-youssef',
			'cohortId' => 'c-8',
			'learnerRef' => 'p-youssef',
			'lifecycle' => 'active',
			'organisationRef' => 'o-jansen',
			'openTask' => 'Vul de geboortedatum van Youssef El Amrani in'
		];
		$enrolments[] = ['id' => 'e-left', 'cohortId' => 'c-8', 'learnerRef' => 'p-0', 'lifecycle' => 'withdrawn'];
		$enrolments[] = ['id' => 'e-other', 'cohortId' => 'c-other', 'learnerRef' => 'p-0', 'lifecycle' => 'active'];

		$this->rows = [
			'cohort' => [
				['id' => 'c-8', 'name' => 'F-gassen 8 okt', 'courseId' => 'fgas', 'dayDate' => '2026-10-08', 'tenant_id' => 't-1'],
				['id' => 'g-1', 'name' => 'Group 1a'],
			],
			'course' => [['id' => 'fgas', 'title' => 'F-gassen: herhaling en examen']],
			'enrolment' => $enrolments,
			'learner-profile' => $profiles,
			'client-organisation' => [['id' => 'o-jansen', 'name' => 'Jansen Installatietechniek', 'contactName' => 'Linda Jansen']],
			'exam-registration' => [],
		];
	}//end setUp()

	private function service(): ExamRegistrationService {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema  = $filters['schema'];
				unset($filters['register'], $filters['schema']);

				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static function (array $row) use ($config, $filters): bool {
							if (isset($config['ids']) === true && in_array($row['id'], $config['ids'], true) === false) {
								return false;
							}

							return array_intersect_assoc($filters, $row) === $filters;
						}
					)
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				$schema = $options->target->schema;
				$object['id'] = ($options->uuid ?? ('new-' . count($this->saves)));
				$this->saves[] = [$schema, $object];
				$found = false;
				foreach (($this->rows[$schema] ?? []) as $i => $row) {
					if ($row['id'] === $object['id']) {
						$this->rows[$schema][$i] = $object;
						$found = true;
					}
				}

				if ($found === false) {
					$this->rows[$schema][] = $object;
				}

				$entity = $this->createMock(originalClassName: ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($object);

				return $entity;
			}
		);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime($this->now));

		return new ExamRegistrationService(objectService: $objects, time: $time);
	}//end service()

	private function opened(): array {
		return $this->service()->open(
			cohortId: 'c-8',
			exam: ['bodyName' => 'Examenbureau Koudetechniek', 'closesAt' => '2026-10-07T12:00:00+02:00', 'examDate' => null, 'requiredFields' => []]
		);
	}//end opened()

	public function testYoussefsMissingBirthDateIsNamedAndHisEmployerWasToldThisMorning(): void {
		$list = $this->opened();

		self::assertSame(expected: 'open', actual: $list['lifecycle']);
		self::assertSame(expected: '2026-10-08', actual: $list['examDate'], message: 'the exam date defaults to the course day');
		self::assertSame(expected: 'F-gassen: herhaling en examen', actual: $list['courseName']);
		self::assertSame(expected: 't-1', actual: $list['tenant_id']);
		self::assertSame(expected: 11, actual: $list['participantCount'], message: 'the withdrawn and the other day are not on it');
		self::assertSame(expected: 10, actual: $list['completeCount']);
		self::assertSame(expected: 'De aanmelding voor het examen van donderdag sluit woensdag om 12.00 uur.', actual: $list['closingLine']);
		self::assertSame(expected: '10 van de 11 deelnemers zijn compleet.', actual: $list['summary']);
		self::assertCount(expectedCount: 1, haystack: $list['incomplete']);
		self::assertSame(
			expected: 'Van Youssef El Amrani (Jansen Installatietechniek) ontbreekt de geboortedatum.',
			actual: $list['incomplete'][0]['sentence']
		);
		self::assertSame(expected: 'Linda Jansen', actual: $list['incomplete'][0]['contactName']);
		self::assertSame(expected: 'De werkgever kreeg vanochtend bericht.', actual: $list['incomplete'][0]['notice']);
		self::assertSame(
			expected: 'Vul de geboortedatum van Youssef El Amrani in',
			actual: $list['incomplete'][0]['employerTask'],
			message: 'the task on Linda Jansen\'s portal'
		);

		[$schema, $stored] = end($this->saves);
		self::assertSame(expected: 'exam-registration', actual: $schema);
		self::assertArrayNotHasKey(key: 'summary', array: $stored, message: 'the readable parts are not stored');
		self::assertStringNotContainsString(needle: '1990-01-0', haystack: json_encode($stored), message: 'the list never carries a birth date');
	}//end testYoussefsMissingBirthDateIsNamedAndHisEmployerWasToldThisMorning()

	public function testOnlyACourseDayWithABodyAndAMomentGetsAList(): void {
		try {
			$this->service()->open(
				cohortId: 'g-1',
				exam: ['bodyName' => 'X', 'closesAt' => '2026-10-07T12:00:00+02:00', 'examDate' => null, 'requiredFields' => []]
			);
			self::fail(message: 'a group without a course is no course day');
		} catch (CourseDayException $exception) {
			self::assertSame(expected: 404, actual: $exception->getStatus());
		}

		$this->expectException(exception: CourseDayException::class);
		$this->expectExceptionMessage(message: 'Name the examining body and the moment the list closes.');
		$this->service()->open(
			cohortId: 'c-8',
			exam: ['bodyName' => ' ', 'closesAt' => '2026-10-07T12:00:00+02:00', 'examDate' => null, 'requiredFields' => []]
		);
	}//end testOnlyACourseDayWithABodyAndAMomentGetsAList()

	public function testAnOpenListFollowsTheProfileOnceTheEmployerFillsItIn(): void {
		$id = $this->opened()['id'];
		foreach ($this->rows['learner-profile'] as $i => $profile) {
			if ($profile['id'] === 'p-youssef') {
				$this->rows['learner-profile'][$i]['birthDate'] = '1999-05-06';
			}
		}

		$list = $this->service()->show(id: $id);

		self::assertSame(expected: 'Alle 11 deelnemers zijn compleet.', actual: $list['summary']);
		self::assertSame(expected: [], actual: $list['incomplete']);
	}//end testAnOpenListFollowsTheProfileOnceTheEmployerFillsItIn()

	public function testTheListClosesAtItsMomentAndOnlyThenExports(): void {
		$id = $this->opened()['id'];

		try {
			$this->service()->export(id: $id);
			self::fail(message: 'an open list is not exported');
		} catch (CourseDayException $exception) {
			self::assertSame(expected: 409, actual: $exception->getStatus());
		}

		$this->now = '2026-10-07T11:45:00+02:00';
		self::assertSame(expected: 0, actual: $this->service()->closeDue(), message: 'not before its moment');
		$this->now = '2026-10-07T12:05:00+02:00';
		self::assertSame(expected: 1, actual: $this->service()->closeDue());

		$closed = $this->service()->show(id: $id);
		self::assertSame(expected: 'closed', actual: $closed['lifecycle']);
		self::assertSame(expected: '2026-10-07T12:05:00+02:00', actual: $closed['closedAt']);
		self::assertNull(actual: $closed['closedBy'], message: 'closed at its moment, by nobody');

		$export = $this->service()->export(id: $id);
		self::assertSame(expected: 'aanmelding-examenbureau-koudetechniek-2026-10-08.csv', actual: $export['filename']);
		$rows = explode("\r\n", trim($export['csv']));
		self::assertSame(expected: 'Naam;Geboortedatum;Geboorteplaats', actual: $rows[0]);
		self::assertCount(expectedCount: 12, haystack: $rows);
		self::assertContains(needle: 'Youssef El Amrani;;Utrecht', haystack: $rows);
		self::assertContains(needle: 'Tom Verbeek;1990-01-01;Utrecht', haystack: $rows, message: 'the export reads the birth date from the profile');
		self::assertSame(expected: '2026-10-07T12:05:00+02:00', actual: $this->service()->show(id: $id)['exportedAt']);
	}//end testTheListClosesAtItsMomentAndOnlyThenExports()

	public function testAClosedListIsMarkedSentOnceAndClosesOnlyOnce(): void {
		$id = $this->opened()['id'];

		try {
			$this->service()->markSent(id: $id, userId: 'rob');
			self::fail(message: 'an open list is not sent');
		} catch (CourseDayException $exception) {
			self::assertSame(expected: 409, actual: $exception->getStatus());
		}

		self::assertSame(expected: 'rob', actual: $this->service()->close(id: $id, userId: 'rob')['closedBy']);
		$sent = $this->service()->markSent(id: $id, userId: 'rob');
		self::assertSame(expected: 'sent', actual: $sent['lifecycle']);
		self::assertSame(expected: 'rob', actual: $sent['sentBy']);
		self::assertSame(expected: $this->now, actual: $sent['sentAt']);

		$this->expectException(exception: CourseDayException::class);
		$this->expectExceptionMessage(message: 'The list is already closed.');
		$this->service()->close(id: $id, userId: 'rob');
	}//end testAClosedListIsMarkedSentOnceAndClosesOnlyOnce()

	public function testResultsAreEnteredOnAClosedListForItsParticipantsOnly(): void {
		$id = $this->opened()['id'];

		try {
			$this->service()->recordResult(id: $id, enrolmentId: 'e-0', result: 'pass', userId: 'rob');
			self::fail(message: 'no results while the list is open');
		} catch (CourseDayException $exception) {
			self::assertSame(expected: 409, actual: $exception->getStatus());
		}

		$this->service()->close(id: $id, userId: 'rob');
		$list = $this->service()->recordResult(id: $id, enrolmentId: 'e-0', result: 'pass', userId: 'rob');
		$line = array_values(array_filter($list['lines'], static fn (array $line): bool => $line['enrolmentId'] === 'e-0'))[0];
		self::assertSame(expected: 'pass', actual: $line['result']);
		self::assertSame(expected: 'rob', actual: $line['resultBy']);

		$cleared = $this->service()->recordResult(id: $id, enrolmentId: 'e-0', result: '', userId: 'rob');
		$line    = array_values(array_filter($cleared['lines'], static fn (array $line): bool => $line['enrolmentId'] === 'e-0'))[0];
		self::assertNull(actual: $line['result']);
		self::assertNull(actual: $line['resultBy']);

		try {
			$this->service()->recordResult(id: $id, enrolmentId: 'e-0', result: 'maybe', userId: 'rob');
			self::fail(message: 'a result is pass or fail');
		} catch (CourseDayException $exception) {
			self::assertSame(expected: 400, actual: $exception->getStatus());
		}

		$this->expectException(exception: CourseDayException::class);
		$this->expectExceptionMessage(message: 'This participant is not on the list.');
		$this->service()->recordResult(id: $id, enrolmentId: 'e-other', result: 'pass', userId: 'rob');
	}//end testResultsAreEnteredOnAClosedListForItsParticipantsOnly()

	public function testTheOpenListsAndTheListsOfADayAreRead(): void {
		$id = $this->opened()['id'];

		self::assertSame(expected: [$id], actual: array_column($this->service()->openLists(), 'id'));
		self::assertSame(expected: [$id], actual: array_column($this->service()->forCourseDay(cohortId: 'c-8'), 'id'));
		self::assertSame(expected: [], actual: $this->service()->forCourseDay(cohortId: 'c-other'));

		$this->expectException(exception: CourseDayException::class);
		$this->expectExceptionMessage(message: 'This registration list does not exist.');
		$this->service()->show(id: 'nope');
	}//end testTheOpenListsAndTheListsOfADayAreRead()
}//end class
