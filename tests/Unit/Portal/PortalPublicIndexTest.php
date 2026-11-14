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
	 * @param callable|null $edit Changes the seeded objects first (schema => rows in, the same out).
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
	 * @param string $portal The portal slug.
	 * @param string $type   The item type.
	 *
	 * @return array<string, array<string, mixed>> By title.
	 */
	private function items(string $portal, string $type): array {
		$items = array_filter($this->index()->forPortal(portal: $portal, today: new DateTimeImmutable(self::MONDAY)), static fn (array $i): bool => $i['type'] === $type);

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
	 * Vaartveld's public toetsweek 1: the four papers of 4 havo with day, time,
	 * subject, room and class, what to bring and the weight, and no name of a
	 * pupil or an invigilator. Toetsweek 2, not marked public, is not in it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function testAPublicTestWeekIsInTheIndexWithoutNames(): void {
		$tests = $this->items(portal: 'vaartveld', type: 'test');

		self::assertSame(['Economie', 'Engels', 'Nederlands', 'Wiskunde A'], $this->sorted(array_keys($tests)));
		$wiskunde = $tests['Wiskunde A'];
		self::assertSame('Di 10 nov, 08.30 - 10.10, Wiskunde A', $wiskunde['meta']);
		self::assertSame(['2026-11-10', '08:30', '10:10'], [$wiskunde['date'], $wiskunde['startTime'], $wiskunde['endTime']]);
		self::assertSame('Aula', $wiskunde['room']);
		self::assertSame(['4 havo'], $wiskunde['groups']);
		self::assertSame('Toetsweek 1, 9 - 13 november', $wiskunde['period']);
		self::assertSame(['Department and year' => ['4 havo'], 'Test week' => ['Toetsweek 1, 9 - 13 november']], $wiskunde['facets']);
		self::assertSame('Je schoolpas, pennen, een geodriehoek en een rekenmachine.', $wiskunde['bringList']);
		self::assertSame(1, $wiskunde['weight']);
		self::assertSame('Ma 9 nov, 08.30 - 10.10, Nederlands', $tests['Nederlands']['meta']);

		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/vo.json'), true)['x-openregister']['seedData']['objects'];
		$text = json_encode(array_values($tests));
		foreach (array_slice($seed['learner-profile'], 0, 400) as $person) {
			$name = trim(($person['givenName'] ?? '') . ' ' . ($person['familyName'] ?? ''));
			self::assertStringNotContainsString($name, (string)$text, 'a sitting names no pupil or guardian');
		}

		foreach (['learnerIds', 'learnerRefs', 'invigilatorIds', 'headcount'] as $key) {
			self::assertArrayNotHasKey($key, $wiskunde);
		}

		self::assertSame([], array_filter($tests, static fn (array $t): bool => str_starts_with($t['period'], 'Toetsweek 2')), 'a week not marked public stays out');
	}//end testAPublicTestWeekIsInTheIndexWithoutNames()

	/**
	 * A sitting the roostermaker moves is read on its new day; a cancelled
	 * sitting, a week still in planning and a week that is over stay out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function testTheIndexFollowsTheSchedule(): void {
		$moved = $this->testsOf(
			edit: static function (array $objects): array {
				foreach ($objects['exam-sitting'] as &$sitting) {
					if (str_contains($sitting['startsAt'], '2026-11-10T08:30') === true) {
						$sitting['startsAt'] = '2026-11-11T13:00:00+01:00';
						$sitting['endsAt']   = '2026-11-11T14:40:00+01:00';
					}

					if (str_contains($sitting['startsAt'], '2026-11-12') === true) {
						$sitting['lifecycle'] = 'cancelled';
					}
				}

				return $objects;
			}
		);
		self::assertSame('Wo 11 nov, 13.00 - 14.40, Wiskunde A', $moved['Wiskunde A']['meta']);
		self::assertArrayNotHasKey('Economie', $moved, 'a cancelled sitting stays out');

		$planning = $this->testsOf(edit: static fn (array $objects): array => self::periods(objects: $objects, fields: ['lifecycle' => 'planning']));
		self::assertSame([], $planning, 'a week still in planning stays out');

		$private = $this->testsOf(edit: static fn (array $objects): array => self::periods(objects: $objects, fields: ['public' => false]));
		self::assertSame([], $private, 'a week not marked public stays out');

		$over = array_filter(
			$this->index()->forPortal(portal: 'vaartveld', today: new DateTimeImmutable('2026-11-14')),
			static fn (array $i): bool => $i['type'] === 'test'
		);
		self::assertSame([], $over, 'a week that is over stays out');
	}//end testTheIndexFollowsTheSchedule()

	/**
	 * Vaartveld's test items after an edit of the seeds, by title.
	 *
	 * @param callable $edit Changes the seeded objects.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function testsOf(callable $edit): array {
		$items = array_filter(
			$this->index(edit: $edit)->forPortal(portal: 'vaartveld', today: new DateTimeImmutable(self::MONDAY)),
			static fn (array $i): bool => $i['type'] === 'test'
		);

		return array_column(array_values($items), null, 'title');
	}//end testsOf()

	/**
	 * The seeds with the given fields set on every test week.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $objects The seeded objects.
	 * @param array<string, mixed>                            $fields  The fields to set.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function periods(array $objects, array $fields): array {
		foreach ($objects['exam-period'] as &$period) {
			$period = array_merge($period, $fields);
		}

		return $objects;
	}//end periods()

	/**
	 * A list sorted, for a comparison that does not depend on order.
	 *
	 * @param array<int, string> $values The values.
	 *
	 * @return array<int, string>
	 */
	private function sorted(array $values): array {
		sort($values);

		return $values;
	}//end sorted()
}//end class
