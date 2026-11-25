<?php

/**
 * The example sets carry the states the Today boards draw on Monday 5 October 2026.
 *
 * The First today rules run over the po and vo sets' own rows, the way
 * FirstTodayProbe hands them in: Daan's register of groep 7 is still open with
 * two parent reports, the intern begeleider's plan of Tess Bakker ends on
 * Friday without an evaluation, a 3HV-B lesson in the second hour ended with no
 * register (the vo set's stand-in for the board's H5a), and two leave requests
 * wait for the head in each set.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/today-first-per-school-role/tasks.md#T6
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Today\FollowUpFacts;
use OCA\Learniq\Service\Today\RegisterFacts;
use PHPUnit\Framework\TestCase;

/**
 * The Today board states of the po and vo example sets.
 */
class TodayFirstExampleStatesTest extends TestCase {

	/**
	 * The Monday of the load week the boards draw.
	 *
	 * @var string
	 */
	private const MONDAY = '2026-10-05';

	/**
	 * The decoded sets, per set and schema slug.
	 *
	 * @var array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private static array $sets = [];

	/**
	 * The objects of one schema in one set.
	 *
	 * @param string $set    The set id (po, vo).
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function of(string $set, string $schema): array {
		if (isset(self::$sets[$set]) === false) {
			$file = dirname(__DIR__, 3) . '/lib/Settings/profiles/' . $set . '.json';
			$data = json_decode((string)file_get_contents($file), true);
			self::$sets[$set] = $data['x-openregister']['seedData']['objects'];
		}

		return (self::$sets[$set][$schema] ?? []);
	}//end of()

	/**
	 * A moment of the Monday in the school's time zone.
	 *
	 * @param string $time The time of day (HH:MM).
	 *
	 * @return DateTimeImmutable
	 */
	private static function at(string $time): DateTimeImmutable {
		return new DateTimeImmutable(self::MONDAY . ' ' . $time, new DateTimeZone('Europe/Amsterdam'));
	}//end at()

	/**
	 * A group as FirstTodayReader::groupsToday() hands it in: the cohort, its
	 * lessons of the Monday, the saved marks per lesson and the reports covering the day.
	 *
	 * @param string $set  The set id.
	 * @param string $name The cohort name.
	 *
	 * @return array<string, mixed>
	 */
	private static function group(string $set, string $name): array {
		$cohort = current(array_filter(self::of(set: $set, schema: 'cohort'), static fn (array $row): bool => $row['name'] === $name));
		self::assertIsArray(actual: $cohort, message: $name);
		$sessions = array_values(
			array_filter(
				self::of(set: $set, schema: 'session'),
				static fn (array $row): bool => ($row['cohortId'] ?? '') === $cohort['uuid'] && substr((string)$row['startsAt'], 0, 10) === self::MONDAY
			)
		);
		$records = [];
		foreach ($sessions as $session) {
			$records[$session['uuid']] = count(
				array_filter(self::of(set: $set, schema: 'attendance-record'), static fn (array $row): bool => $row['sessionId'] === $session['uuid'])
			);
		}

		$reports = array_filter(
			self::of(set: $set, schema: 'excuse-request'),
			static fn (array $row): bool => in_array($row['learnerId'] ?? '', (array)$cohort['learnerIds'], true) === true
				&& ($row['dateFrom'] ?? '') <= self::MONDAY && ($row['dateTo'] ?? '') >= self::MONDAY
		);

		return ['cohort' => $cohort, 'sessions' => $sessions, 'records' => $records, 'reports' => count($reports)];
	}//end group()

	/**
	 * Daan opens Today at 8.40: groep 7's register is open and two pupils were reported by their parents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#scenario-meester-daan-before-845
	 */
	public function testGroep7RegisterIsOpenWithTwoParentReports(): void {
		$facts = (new RegisterFacts())->registerNotFilled(groups: [self::group(set: 'po', name: 'Groep 7')], now: self::at(time: '08:40'));

		self::assertSame(expected: 1, actual: $facts['count']);
		self::assertSame(expected: 'Groep 7', actual: $facts['cohortName']);
		self::assertSame(expected: 2, actual: $facts['reportCount']);
	}//end testGroep7RegisterIsOpenWithTwoParentReports()

	/**
	 * The two reported pupils carry no mark and no absent day yet, while the list still reads them absent from their reports.
	 *
	 * @return void
	 */
	public function testTheReportedPupilsAreNotMarkedYet(): void {
		$profiles = array_filter(
			self::of(set: 'po', schema: 'learner-profile'),
			static fn (array $row): bool => in_array([$row['givenName'], $row['familyName']], [['Lina', 'Kievitlaan'], ['Hamza', 'Elzenhof']], true)
		);
		self::assertCount(expectedCount: 2, haystack: $profiles);
		foreach ($profiles as $profile) {
			$marks = array_filter(self::of(set: 'po', schema: 'attendance-record'), static fn (array $row): bool => $row['learnerRef'] === $profile['uuid']);
			self::assertSame(expected: [], actual: array_values($marks), message: $profile['givenName']);
			$reports = array_filter(
				self::of(set: 'po', schema: 'excuse-request'),
				static fn (array $row): bool => $row['learnerRef'] === $profile['uuid'] && $row['dateFrom'] === self::MONDAY && $row['lifecycle'] === 'approved'
			);
			self::assertCount(expectedCount: 1, haystack: $reports, message: $profile['givenName']);
			$summary = current(
				array_filter(
					self::of(set: 'po', schema: 'attendance-summary'),
					static fn (array $row): bool => $row['learnerRef'] === $profile['uuid'] && $row['schoolYear'] === '2026-2027'
				)
			);
			self::assertSame(expected: 0, actual: $summary['absentDays'], message: $profile['givenName']);
		}
	}//end testTheReportedPupilsAreNotMarkedYet()

	/**
	 * The intern begeleider's first rule finds Tess Bakker's plan, which ends on Friday without an evaluation.
	 *
	 * @return void
	 */
	public function testTessBakkersPlanEndsOnFridayWithoutEvaluation(): void {
		$plans = array_values(array_filter(self::of(set: 'po', schema: 'learning-plan'), static fn (array $row): bool => $row['lifecycle'] === 'active'));
		$facts = (new FollowUpFacts())->planEnding(
			plans: ['group-plan' => self::of(set: 'po', schema: 'group-plan'), 'learning-plan' => $plans],
			today: self::MONDAY
		);

		self::assertSame(expected: 1, actual: $facts['count']);
		self::assertSame(expected: 'learning-plan', actual: $facts['schema']);
		self::assertSame(expected: '2026-10-09', actual: $facts['endsOn']);
		self::assertSame(expected: 'Friday', actual: (new DateTimeImmutable($facts['endsOn']))->format('l'));

		$plan = current(array_filter($plans, static fn (array $row): bool => $row['uuid'] === $facts['planId']));
		self::assertSame(expected: 'po-ib-01', actual: $plan['coordinatorId']);
		$profiles = self::of(set: 'po', schema: 'learner-profile');
		$pupil = current(array_filter($profiles, static fn (array $row): bool => $row['ncUserId'] === $plan['learnerId']));
		self::assertSame(expected: ['Tess', 'Bakker'], actual: [$pupil['givenName'], $pupil['familyName']]);
		$enrolment = current(array_filter(self::of(set: 'po', schema: 'enrolment'), static fn (array $row): bool => $row['learnerRef'] === $pupil['uuid']));
		self::assertSame(expected: $plan['cohortId'], actual: $enrolment['cohortId']);
	}//end testTessBakkersPlanEndsOnFridayWithoutEvaluation()

	/**
	 * A 3HV-B teacher at 10.15: the second-hour lesson ended with no register, the first hour was filled.
	 *
	 * @return void
	 */
	public function testTheSecondHourOf3hvbEndedWithoutARegister(): void {
		$group = self::group(set: 'vo', name: '3HV-B');
		$sessions = array_map(
			static fn (array $session): array => array_merge($session, ['markCount' => $group['records'][$session['uuid']]]),
			$group['sessions']
		);
		$facts = (new RegisterFacts())->lessonRegisterOpen(sessions: $sessions, now: self::at(time: '10:15'));

		self::assertSame(expected: 1, actual: $facts['count']);
		self::assertSame(expected: 'Wiskunde A', actual: $facts['sessionTitle']);
		self::assertSame(expected: '2026-10-05T10:10:00+02:00', actual: $facts['endsAt']);
	}//end testTheSecondHourOf3hvbEndedWithoutARegister()

	/**
	 * Two leave requests wait for the head's decision in each set.
	 *
	 * @return void
	 */
	public function testTwoLeaveRequestsWaitInEachSet(): void {
		foreach (['po', 'vo'] as $set) {
			$open = array_filter(self::of(set: $set, schema: 'leave-request'), static fn (array $row): bool => $row['lifecycle'] === 'submitted');
			self::assertCount(expectedCount: 2, haystack: $open, message: $set);
		}
	}//end testTwoLeaveRequestsWaitInEachSet()
}//end class
