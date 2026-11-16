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
		$names      = ['Tom Verbeek', 'Sanne de Wit', 'Mehmet Kaya', 'Lotte Bos', 'Daan Smit', 'Eva Mulder', 'Bram Visser', 'Noor Bakker', 'Ruben Jong', 'Iris Peters'];
		foreach ($names as $i => $name) {
			[$given, $family] = explode(' ', $name, 2);
			$profiles[]   = ['id' => 'p-' . $i, 'givenName' => $given, 'familyName' => $family, 'birthDate' => '1990-01-0' . ($i % 9 + 1), 'placeOfBirth' => 'Utrecht', 'organisationRef' => 'o-jansen'];
			$enrolments[] = ['id' => 'e-' . $i, 'cohortId' => 'c-8', 'learnerRef' => 'p-' . $i, 'lifecycle' => 'active', 'organisationRef' => 'o-jansen'];
		}

		$profiles[]   = ['id' => 'p-youssef', 'givenName' => 'Youssef', 'familyName' => 'El Amrani', 'placeOfBirth' => 'Utrecht', 'organisationRef' => 'o-jansen'];
		$enrolments[] = ['id' => 'e-youssef', 'cohortId' => 'c-8', 'learnerRef' => 'p-youssef', 'lifecycle' => 'active', 'organisationRef' => 'o-jansen'];
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
		$objects = $this->createMock(ObjectService::class);
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

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($object);

				return $entity;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime($this->now));

		return new ExamRegistrationService(objectService: $objects, time: $time);
	}//end service()

	private function opened(): array {
		return $this->service()->open(cohortId: 'c-8', bodyName: 'Examenbureau Koudetechniek', closesAt: '2026-10-07T12:00:00+02:00', examDate: null, requiredFields: []);
	}//end opened()

	public function testYoussefsMissingBirthDateIsNamedAndHisEmployerWasToldThisMorning(): void {
		$list = $this->opened();

		self::assertSame('open', $list['lifecycle']);
		self::assertSame('2026-10-08', $list['examDate'], 'the exam date defaults to the course day');
		self::assertSame('F-gassen: herhaling en examen', $list['courseName']);
		self::assertSame('t-1', $list['tenant_id']);
		self::assertSame(11, $list['participantCount'], 'the withdrawn and the other day are not on it');
		self::assertSame(10, $list['completeCount']);
		self::assertSame('De aanmelding voor het examen van donderdag sluit woensdag om 12.00 uur.', $list['closingLine']);
		self::assertSame('10 van de 11 deelnemers zijn compleet.', $list['summary']);
		self::assertCount(1, $list['incomplete']);
		self::assertSame('Van Youssef El Amrani (Jansen Installatietechniek) ontbreekt de geboortedatum.', $list['incomplete'][0]['sentence']);
		self::assertSame('Linda Jansen', $list['incomplete'][0]['contactName']);
		self::assertSame('De werkgever kreeg vanochtend bericht.', $list['incomplete'][0]['notice']);

		[$schema, $stored] = end($this->saves);
		self::assertSame('exam-registration', $schema);
		self::assertArrayNotHasKey('summary', $stored, 'the readable parts are not stored');
		self::assertStringNotContainsString('1990-01-0', json_encode($stored), 'the list never carries a birth date');
	}//end testYoussefsMissingBirthDateIsNamedAndHisEmployerWasToldThisMorning()

	public function testOnlyACourseDayWithABodyAndAMomentGetsAList(): void {
		try {
			$this->service()->open(cohortId: 'g-1', bodyName: 'X', closesAt: '2026-10-07T12:00:00+02:00', examDate: null, requiredFields: []);
			self::fail('a group without a course is no course day');
		} catch (CourseDayException $exception) {
			self::assertSame(404, $exception->getStatus());
		}

		$this->expectException(CourseDayException::class);
		$this->expectExceptionMessage('Name the examining body and the moment the list closes.');
		$this->service()->open(cohortId: 'c-8', bodyName: ' ', closesAt: '2026-10-07T12:00:00+02:00', examDate: null, requiredFields: []);
	}//end testOnlyACourseDayWithABodyAndAMomentGetsAList()

	public function testAnOpenListFollowsTheProfileOnceTheEmployerFillsItIn(): void {
		$id = $this->opened()['id'];
		foreach ($this->rows['learner-profile'] as $i => $profile) {
			if ($profile['id'] === 'p-youssef') {
				$this->rows['learner-profile'][$i]['birthDate'] = '1999-05-06';
			}
		}

		$list = $this->service()->show(id: $id);

		self::assertSame('Alle 11 deelnemers zijn compleet.', $list['summary']);
		self::assertSame([], $list['incomplete']);
	}//end testAnOpenListFollowsTheProfileOnceTheEmployerFillsItIn()

	public function testTheListClosesAtItsMomentAndOnlyThenExports(): void {
		$id = $this->opened()['id'];

		try {
			$this->service()->export(id: $id);
			self::fail('an open list is not exported');
		} catch (CourseDayException $exception) {
			self::assertSame(409, $exception->getStatus());
		}

		$this->now = '2026-10-07T11:45:00+02:00';
		self::assertSame(0, $this->service()->closeDue(), 'not before its moment');
		$this->now = '2026-10-07T12:05:00+02:00';
		self::assertSame(1, $this->service()->closeDue());

		$closed = $this->service()->show(id: $id);
		self::assertSame('closed', $closed['lifecycle']);
		self::assertSame('2026-10-07T12:05:00+02:00', $closed['closedAt']);
		self::assertNull($closed['closedBy'], 'closed at its moment, by nobody');

		$export = $this->service()->export(id: $id);
		self::assertSame('aanmelding-examenbureau-koudetechniek-2026-10-08.csv', $export['filename']);
		$rows = explode("\r\n", trim($export['csv']));
		self::assertSame('Naam;Geboortedatum;Geboorteplaats', $rows[0]);
		self::assertCount(12, $rows);
		self::assertContains('Youssef El Amrani;;Utrecht', $rows);
		self::assertContains('Tom Verbeek;1990-01-01;Utrecht', $rows, 'the export reads the birth date from the profile');
		self::assertSame('2026-10-07T12:05:00+02:00', $this->service()->show(id: $id)['exportedAt']);
	}//end testTheListClosesAtItsMomentAndOnlyThenExports()

	public function testAClosedListIsMarkedSentOnceAndClosesOnlyOnce(): void {
		$id = $this->opened()['id'];

		try {
			$this->service()->markSent(id: $id, userId: 'rob');
			self::fail('an open list is not sent');
		} catch (CourseDayException $exception) {
			self::assertSame(409, $exception->getStatus());
		}

		self::assertSame('rob', $this->service()->close(id: $id, userId: 'rob')['closedBy']);
		$sent = $this->service()->markSent(id: $id, userId: 'rob');
		self::assertSame('sent', $sent['lifecycle']);
		self::assertSame('rob', $sent['sentBy']);
		self::assertSame($this->now, $sent['sentAt']);

		$this->expectException(CourseDayException::class);
		$this->expectExceptionMessage('The list is already closed.');
		$this->service()->close(id: $id, userId: 'rob');
	}//end testAClosedListIsMarkedSentOnceAndClosesOnlyOnce()

	public function testResultsAreEnteredOnAClosedListForItsParticipantsOnly(): void {
		$id = $this->opened()['id'];

		try {
			$this->service()->recordResult(id: $id, enrolmentId: 'e-0', result: 'pass', userId: 'rob');
			self::fail('no results while the list is open');
		} catch (CourseDayException $exception) {
			self::assertSame(409, $exception->getStatus());
		}

		$this->service()->close(id: $id, userId: 'rob');
		$list = $this->service()->recordResult(id: $id, enrolmentId: 'e-0', result: 'pass', userId: 'rob');
		$line = array_values(array_filter($list['lines'], static fn (array $line): bool => $line['enrolmentId'] === 'e-0'))[0];
		self::assertSame('pass', $line['result']);
		self::assertSame('rob', $line['resultBy']);

		$cleared = $this->service()->recordResult(id: $id, enrolmentId: 'e-0', result: '', userId: 'rob');
		$line    = array_values(array_filter($cleared['lines'], static fn (array $line): bool => $line['enrolmentId'] === 'e-0'))[0];
		self::assertNull($line['result']);
		self::assertNull($line['resultBy']);

		try {
			$this->service()->recordResult(id: $id, enrolmentId: 'e-0', result: 'maybe', userId: 'rob');
			self::fail('a result is pass or fail');
		} catch (CourseDayException $exception) {
			self::assertSame(400, $exception->getStatus());
		}

		$this->expectException(CourseDayException::class);
		$this->expectExceptionMessage('This participant is not on the list.');
		$this->service()->recordResult(id: $id, enrolmentId: 'e-other', result: 'pass', userId: 'rob');
	}//end testResultsAreEnteredOnAClosedListForItsParticipantsOnly()

	public function testTheOpenListsAndTheListsOfADayAreRead(): void {
		$id = $this->opened()['id'];

		self::assertSame([$id], array_column($this->service()->openLists(), 'id'));
		self::assertSame([$id], array_column($this->service()->forCourseDay(cohortId: 'c-8'), 'id'));
		self::assertSame([], $this->service()->forCourseDay(cohortId: 'c-other'));

		$this->expectException(CourseDayException::class);
		$this->expectExceptionMessage('This registration list does not exist.');
		$this->service()->show(id: 'nope');
	}//end testTheOpenListsAndTheListsOfADayAreRead()
}//end class
