<?php

/**
 * What a school shows a visitor of its portal, read over the real example
 * sets: the Warmtepompacademie's courses with their next dates and place, the
 * Esdoornveen programmes with level and learning path, the Wilgenboom's
 * school-wide days; each example portal only its own set
 * (portal-public-index).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use DateTimeImmutable;
use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\PortalPublicIndex;
use OCA\Learniq\Portal\PublicEventIndex;
use OCA\Learniq\Portal\PublicTestScheduleIndex;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The public index over the seeded schools.
 */
class PortalPublicIndexTest extends TestCase {

	private const MONDAY = '2026-10-05';

	/**
	 * An index over ALL example sets at once, as the proof instance carries
	 * them: OpenRegister answers from every set's seed objects together.
	 *
	 * @param callable|null $edit Changes the seed objects first, as a school would.
	 *
	 * @return PortalPublicIndex
	 */
	private function index(?callable $edit=null): PortalPublicIndex {
		$objects = [];
		foreach (['po', 'vo', 'mbo', 'training'] as $set) {
			$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/' . $set . '.json'), true)['x-openregister']['seedData']['objects'];
			foreach ($seed as $schema => $rows) {
				$objects[$schema] = array_merge(($objects[$schema] ?? []), $rows);
			}
		}

		if ($edit !== null) {
			$objects = $edit($objects);
		}

		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			static function (array $config) use ($objects): array {
				$filters = $config['filters'];
				$rows    = ($objects[$filters['schema']] ?? []);
				unset($filters['register'], $filters['schema']);
				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		return new PortalPublicIndex($store, $this->createMock(LoggerInterface::class));
	}//end index()

	/**
	 * The items of one type for one portal on Monday 5 October 2026.
	 *
	 * @param string        $portal The portal slug.
	 * @param string        $type   The item type.
	 * @param callable|null $edit   Changes the seed objects first.
	 *
	 * @return array<string, array<string, mixed>> By title.
	 */
	private function items(string $portal, string $type, ?callable $edit=null): array {
		$items = array_filter($this->index(edit: $edit)->forPortal(portal: $portal, today: new DateTimeImmutable(self::MONDAY)), static fn (array $i): bool => $i['type'] === $type);

		return array_column(array_values($items), null, 'title');
	}//end items()

	/**
	 * The academy's courses carry their next run, its days and the other runs.
	 *
	 * @return void
	 */
	public function testTheAcademyShowsItsCoursesWithTheirNextDates(): void {
		$courses = $this->items(portal: 'warmtepompacademie', type: 'course');

		$fgas = $courses['F-gassen: herhaling en examen'];
		self::assertSame('2026-10-08', $fgas['date']);
		// "Donderdag · 1 dag · certificaat" and a link to the course list (REPORT-2, item 12).
		self::assertSame(['Donderdag', '1 day', 'certificate'], array_slice($fgas['meta'], 0, 3));
		self::assertSame('/cursusaanbod', $fgas['href']);
		// Places left from the capacity (board-data-the-schemas-lacked): 4 places, 3 booked.
		self::assertSame(['One place left', 'warning'], [$fgas['note'], $fgas['noteTone']]);
		self::assertSame(['Praktijkhal Zuiddrecht'], $fgas['facets']['Venue']);
		self::assertSame(['Oktober 2026'], $fgas['facets']['Start in']);

		$lw = $courses['Lucht-water warmtepomp: ontwerp en inbedrijfstelling'];
		self::assertSame(['2026-11-03', '2026-11-10'], [$lw['date'], $lw['endDate']]);
		self::assertSame(['Dinsdag and woensdag', '3 days', 'advanced'], array_slice($lw['meta'], 0, 3));

		$wzi = $courses['Waterzijdig inregelen'];
		self::assertSame('2026-10-15', $wzi['date'], 'the run of 1 October is over');
		self::assertSame(['Donderdag', '1 day', 'basic'], array_slice($wzi['meta'], 0, 3));
		self::assertSame(['6 places free', 'positive'], [$wzi['note'], $wzi['noteTone']], '6 places, none taken');
		self::assertSame(['3 places left', 'warning'], [$courses['Warmtepompen installeren: basis']['note'], $courses['Warmtepompen installeren: basis']['noteTone']], '4 places, 1 taken');
		self::assertArrayNotHasKey('BHV basis 2026-2027 (concept)', $courses, 'a draft course stays out');
		self::assertArrayNotHasKey('BHV basis', $courses, 'a course with no run to come stays out');
	}//end testTheAcademyShowsItsCoursesWithTheirNextDates()

	/**
	 * Esdoornveen's programmes carry their level and learning path.
	 *
	 * @return void
	 */
	public function testTheCollegeShowsItsProgrammesWithLevelAndPath(): void {
		$programmes = $this->items(portal: 'esdoornveen', type: 'programme');

		self::assertSame(['Logistiek medewerker', 'Verzorgende IG', 'Software developer', 'Mechatronica'], array_keys($programmes));
		self::assertSame(['Level 4'], $programmes['Mechatronica']['facets']['Level']);
		self::assertSame(['BOL', 'BBL'], $programmes['Mechatronica']['facets']['Learning path']);
	}//end testTheCollegeShowsItsProgrammesWithLevelAndPath()

	/**
	 * The primary school's calendar: school-wide days still to come only.
	 *
	 * @return void
	 */
	public function testThePrimarySchoolShowsItsSchoolWideDaysToCome(): void {
		$days = $this->items(portal: 'wilgenboom', type: 'event');

		self::assertArrayHasKey('Schoolfotograaf', $days);
		self::assertArrayHasKey('Studiedag', $days);
		self::assertArrayNotHasKey('Naar de kinderboerderij', $days, 'a day for some groups only stays out');
		self::assertArrayNotHasKey('Opening Kinderboekenweek', $days, 'a day that is over stays out');
	}//end testThePrimarySchoolShowsItsSchoolWideDaysToCome()

	/**
	 * Every school day carries the category a calendar block filters on, so a
	 * block asking for holidays and days off gets those and not the photographer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-every-school-day-in-the-index-has-a-category
	 */
	public function testEverySchoolDayCarriesItsCategory(): void {
		$days = $this->items(portal: 'wilgenboom', type: 'event');

		self::assertSame('holiday', $days['Herfstvakantie']['category']);
		self::assertSame('Holiday', $days['Herfstvakantie']['categoryLabel']);
		self::assertSame('2026-10-25', $days['Herfstvakantie']['endDate']);
		self::assertSame('day-off', $days['Studiedag']['category']);
		self::assertSame('Study day or day off', $days['Studiedag']['categoryLabel']);
		self::assertSame('activity', $days['Schoolfotograaf']['category']);
		self::assertSame('School activity', $days['Schoolfotograaf']['categoryLabel']);
		self::assertSame('parent-evening', $days['Ouderavond']['category']);
		self::assertSame('Parent evening', $days['Ouderavond']['categoryLabel']);

		$chosen = array_keys(array_filter($days, static fn (array $day): bool => in_array($day['category'], ['holiday', 'day-off'], true)));
		sort($chosen);
		self::assertSame(['Herfstvakantie', 'Kerstvakantie', 'Studiedag'], $chosen);
	}//end testEverySchoolDayCarriesItsCategory()

	/**
	 * Each kind of day falls in one of the four categories; an unknown or missing kind is a school activity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-every-school-day-in-the-index-has-a-category
	 */
	public function testEveryKindFallsInOneCategory(): void {
		$expected = [
			'holiday' => 'holiday',
			'study-day' => 'day-off',
			'day-off' => 'day-off',
			'parent-evening' => 'parent-evening',
			'event' => 'activity',
			'sports-day' => 'activity',
			'trip' => 'activity',
			'celebration' => 'activity',
			'other' => 'activity',
			'' => 'activity',
		];

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$kinds    = $register['components']['schemas']['SchoolEvent']['properties']['kind']['enum'];
		self::assertSame([], array_diff($kinds, array_keys($expected)), 'a kind of day with no category');

		foreach ($expected as $kind => $category) {
			self::assertSame($category, PublicEventIndex::categoryOf(kind: $kind), $kind);
		}
	}//end testEveryKindFallsInOneCategory()

	/**
	 * On an instance with every example set, a portal shows only its own;
	 * the namespace map matches the sets' generated uuids.
	 *
	 * @return void
	 */
	public function testEachExamplePortalShowsOnlyItsOwnSet(): void {
		self::assertSame([], $this->items(portal: 'wilgenboom', type: 'course') + $this->items(portal: 'wilgenboom', type: 'programme'), 'po has no course runs or programmes');
		self::assertArrayNotHasKey('Mechatronica', $this->items(portal: 'warmtepompacademie', type: 'programme'));
		self::assertArrayNotHasKey('Schoolfotograaf', $this->items(portal: 'vaartveld', type: 'event'));

		foreach (PortalPublicIndex::SET_NAMESPACE as $set => $namespace) {
			$path = __DIR__ . '/../../../lib/Settings/profiles/' . $set . '.json';
			if (is_file($path) === true) {
				$school = json_decode((string)file_get_contents($path), true)['x-openregister']['seedData']['objects']['school'][0];
				self::assertStringStartsWith($namespace, $school['uuid'], $set . ' is generated in its namespace');
			}
		}

		self::assertNotSame([], $this->index()->forPortal(portal: 'some-other-school', today: new DateTimeImmutable(self::MONDAY)), 'any other portal shows everything public');
	}//end testEachExamplePortalShowsOnlyItsOwnSet()

	/**
	 * The provider answers through the service, and nothing without it.
	 *
	 * @return void
	 */
	public function testTheProviderAnswersThroughTheService(): void {
		self::assertSame([], (new PortalContributionProvider())->getPublicIndex('wilgenboom'));
		self::assertNotSame([], (new PortalContributionProvider(publicIndex: $this->index()))->getPublicIndex('esdoornveen'));
	}//end testTheProviderAnswersThroughTheService()

	/**
	 * The school marked toetsweek 1 public: the website's test schedule reads
	 * day, time, subject and room for 4 havo, and nothing about who sits it or
	 * who invigilates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function testAPublicTestWeekIsInTheIndexWithoutNames(): void {
		$tests = $this->items(portal: 'vaartveld', type: 'test');

		self::assertSame(['Nederlands', 'Wiskunde A', 'Engels', 'Geschiedenis'], array_keys($tests), 'in order of day and time');

		$wa = $tests['Wiskunde A'];
		self::assertSame('Di 10 nov, 08.30 - 10.10, Wiskunde A', $wa['line']);
		self::assertSame(['2026-11-10', '08.30', '10.10'], [$wa['date'], $wa['startTime'], $wa['endTime']]);
		self::assertSame('Lokaal H1.03 (4H1)', $wa['room']);
		self::assertSame('Toetsweek 1, 9 - 13 november', $wa['period']);
		self::assertSame([['department' => 'havo', 'year' => 4, 'label' => '4 havo']], $wa['groups']);
		self::assertSame(['Department and year' => ['4 havo'], 'Test week' => ['Toetsweek 1, 9 - 13 november']], $wa['facets']);
		self::assertSame('Exam schedule', $wa['kind']);
		self::assertSame(1, $wa['weight'], 'tw1 of the wiskunde A plan');
		self::assertStringContainsString('grafische rekenmachine', $wa['bring']);

		// Only these keys leave the index: no learner ids, no invigilators, no headcount.
		self::assertSame(
			['bring', 'date', 'endTime', 'facets', 'groups', 'id', 'kind', 'line', 'period', 'periodId', 'room', 'startTime', 'title', 'type', 'weight'],
			$this->sortedKeys($wa)
		);
		$encoded = (string)json_encode($tests);
		self::assertStringNotContainsString('learnerIds', $encoded);
		self::assertStringNotContainsString('vo-leerling', $encoded);

		self::assertSame([], $this->items(portal: 'wilgenboom', type: 'test'), 'another set has no test week of vaartveld');
	}//end testAPublicTestWeekIsInTheIndexWithoutNames()

	/**
	 * The roostermaker moves the wiskunde A test to Wednesday: the next read
	 * of the index has it on Wednesday.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function testARescheduledTestFollows(): void {
		$moved = $this->items(
			portal: 'vaartveld',
			type: 'test',
			edit: static function (array $objects): array {
				foreach ($objects['exam-sitting'] as $i => $sitting) {
					if ($sitting['startsAt'] === '2026-11-10T08:30:00+01:00') {
						$objects['exam-sitting'][$i]['startsAt'] = '2026-11-11T13:00:00+01:00';
						$objects['exam-sitting'][$i]['endsAt']   = '2026-11-11T14:40:00+01:00';
					}
				}

				return $objects;
			}
		);

		self::assertSame('Wo 11 nov, 13.00 - 14.40, Wiskunde A', $moved['Wiskunde A']['line']);
		self::assertSame(['Nederlands', 'Engels', 'Wiskunde A', 'Geschiedenis'], array_keys($moved));
	}//end testARescheduledTestFollows()

	/**
	 * A week the school did not mark public, a week still in planning, and a
	 * cancelled test stay off the website.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function testAWeekNotPublicOrInPlanningStaysOut(): void {
		$period = static function (array $changes): callable {
			return static function (array $objects) use ($changes): array {
				foreach ($objects['exam-period'] as $i => $row) {
					if ($row['name'] === 'Toetsweek 1') {
						$objects['exam-period'][$i] = array_merge($row, $changes);
					}
				}

				return $objects;
			};
		};

		self::assertSame([], $this->items(portal: 'vaartveld', type: 'test', edit: $period(['public' => false])));
		self::assertSame([], $this->items(portal: 'vaartveld', type: 'test', edit: $period(['lifecycle' => 'planning'])));
		self::assertSame([], $this->items(portal: 'vaartveld', type: 'test', edit: $period(['endsOn' => '2026-10-02'])), 'a week that is over');

		$cancelled = $this->items(
			portal: 'vaartveld',
			type: 'test',
			edit: static function (array $objects): array {
				$objects['exam-sitting'][0]['lifecycle'] = 'cancelled';
				return $objects;
			}
		);
		self::assertArrayNotHasKey('Nederlands', $cancelled);
		self::assertCount(3, $cancelled);
	}//end testAWeekNotPublicOrInPlanningStaysOut()

	/**
	 * The labels of a test week and a test line.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function testTheTestWeekAndLineRead(): void {
		$week = static fn (string $name, string $first, string $last): string => PublicTestScheduleIndex::periodLabel(
			period: ['name' => $name, 'startsOn' => $first, 'endsOn' => $last]
		);
		self::assertSame(expected: 'Toetsweek 2, 7 - 11 december', actual: $week('Toetsweek 2', '2026-12-07', '2026-12-11'));
		self::assertSame(expected: 'Toetsweek 3, 30 maart - 3 april', actual: $week('Toetsweek 3', '2027-03-30', '2027-04-03'));
		self::assertSame(expected: 'Herkansing, 4 juni', actual: $week('Herkansing', '2027-06-04', '2027-06-04'));
		self::assertSame(
			expected: 'Vr 4 jun, Frans',
			actual: PublicTestScheduleIndex::line(startsAt: '2027-06-04', endsAt: '', subject: 'Frans')
		);
		self::assertSame(
			expected: 'Ma 9 nov, 08.30 - 10.10, Nederlands',
			actual: PublicTestScheduleIndex::line(startsAt: '2026-11-09T08:30:00+01:00', endsAt: '2026-11-09T10:10:00+01:00', subject: 'Nederlands')
		);
		self::assertFalse(
			condition: PublicTestScheduleIndex::isPublicAndToCome(period: ['endsOn' => '2026-11-13', 'lifecycle' => 'published'], today: self::MONDAY),
			message: 'not marked public'
		);
		self::assertTrue(
			condition: PublicTestScheduleIndex::isPublicAndToCome(
				period: ['endsOn' => '2026-11-13', 'lifecycle' => 'published', 'public' => true],
				today: self::MONDAY
			)
		);
	}//end testTheTestWeekAndLineRead()

	/**
	 * An item's keys, sorted.
	 *
	 * @param array<string, mixed> $item The item.
	 *
	 * @return array<int, string>
	 */
	private function sortedKeys(array $item): array {
		$keys = array_keys($item);
		sort($keys);

		return $keys;
	}//end sortedKeys()
}//end class
