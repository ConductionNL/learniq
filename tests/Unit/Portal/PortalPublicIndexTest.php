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
	 * @return PortalPublicIndex
	 */
	private function index(): PortalPublicIndex {
		$objects = [];
		foreach (['po', 'vo', 'mbo', 'training'] as $set) {
			$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/' . $set . '.json'), true)['x-openregister']['seedData']['objects'];
			foreach ($seed as $schema => $rows) {
				$objects[$schema] = array_merge(($objects[$schema] ?? []), $rows);
			}
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
		self::assertSame('1 day', $fgas['meta'][0]);
		self::assertSame(['Praktijkhal Zuiddrecht'], $fgas['facets']['Venue']);
		self::assertSame(['Oktober 2026'], $fgas['facets']['Start in']);

		$lw = $courses['Lucht-water warmtepomp: ontwerp en inbedrijfstelling'];
		self::assertSame(['2026-11-03', '2026-11-10'], [$lw['date'], $lw['endDate']]);
		self::assertSame('3 days', $lw['meta'][0]);

		$wzi = $courses['Waterzijdig inregelen'];
		self::assertSame('2026-10-15', $wzi['date'], 'the run of 1 October is over');
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
}//end class
