<?php

/**
 * Learniq PublicCourseIndex tests
 *
 * Pins, over a small hand-written store, which courses reach a portal's
 * public index, which lesson days count, and how a run's weekdays read.
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

use OCA\Learniq\Portal\PublicCourseIndex;
use OCA\Learniq\Portal\PublicIndexReads;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The course part of a portal's public index.
 */
class PublicCourseIndexTest extends TestCase {

	private const TODAY = '2026-10-05';

	/**
	 * The index over these rows by schema; sessions are answered for any
	 * cohort filter, so the index's own cohort check is what keeps them apart.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows The rows.
	 *
	 * @return PublicCourseIndex
	 */
	private function index(array $rows): PublicCourseIndex {
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			static fn (array $config): array => ($rows[$config['filters']['schema']] ?? [])
		);

		return new PublicCourseIndex(reads: new PublicIndexReads($store, $this->createMock(LoggerInterface::class)));
	}//end index()

	/**
	 * A lesson of a cohort.
	 *
	 * @param string $cohortId  The cohort.
	 * @param string $startsAt  The start.
	 * @param string $lifecycle The lesson's lifecycle.
	 *
	 * @return array<string, mixed>
	 */
	private static function lesson(string $cohortId, string $startsAt, string $lifecycle='planned'): array {
		return ['cohortId' => $cohortId, 'startsAt' => $startsAt, 'lifecycle' => $lifecycle];
	}//end lesson()

	/**
	 * Which courses are offered, and which lesson days a run has.
	 *
	 * @return void
	 */
	public function testOfferedCoursesAndTheirDaysToCome(): void {
		$items = $this->index(
			[
				'course'  => [
					['id' => 'c-corp', 'name' => 'Corporate', 'lifecycle' => 'published', 'level' => 'corporate'],
					['id' => 'c-self', 'name' => 'Self', 'lifecycle' => 'published', 'selfEnrolment' => true],
					['id' => 'c-draft', 'name' => 'Draft', 'lifecycle' => 'draft', 'level' => 'corporate'],
					['id' => 'c-school', 'name' => 'School', 'lifecycle' => 'published', 'level' => 'vo', 'selfEnrolment' => 'yes'],
					['id' => 'c-norun', 'name' => 'No run', 'lifecycle' => 'published', 'level' => 'corporate'],
				],
				'cohort'  => [
					['id' => 'k-corp', 'courseId' => 'c-corp', 'lifecycle' => 'active'],
					['id' => 'k-self', 'courseId' => 'c-self', 'lifecycle' => 'active'],
					['id' => 'k-draft', 'courseId' => 'c-draft', 'lifecycle' => 'active'],
					['id' => 'k-school', 'courseId' => 'c-school', 'lifecycle' => 'active'],
					['id' => 'k-norun', 'courseId' => 'c-norun', 'lifecycle' => 'active'],
				],
				'session' => [
					self::lesson('k-corp', '2026-10-08T09:00:00+02:00'),
					self::lesson('k-corp', '2026-10-08T13:00:00+02:00'),
					self::lesson('k-corp', '2026-10-06T09:00:00+02:00'),
					self::lesson('k-corp', '2026-10-07T09:00:00+02:00', 'cancelled'),
					self::lesson('k-corp', '2026-10-01T09:00:00+02:00'),
					self::lesson('k-corp', ''),
					self::lesson('k-self', '2026-10-05T09:00:00+02:00'),
					self::lesson('k-draft', '2026-10-09T09:00:00+02:00'),
					self::lesson('k-school', '2026-10-09T09:00:00+02:00'),
				],
			]
		)->items(namespace: '', today: self::TODAY);

		self::assertSame(['Corporate', 'Self'], array_column($items, 'title'));
		self::assertSame(['2026-10-06', '2026-10-08'], [$items[0]['date'], $items[0]['endDate']]);
		self::assertSame(['Dinsdag and donderdag', '2 days'], array_slice($items[0]['meta'], 0, 2));
		self::assertSame(['2026-10-05', '2026-10-05'], [$items[1]['date'], $items[1]['endDate']]);
		self::assertSame(['Maandag', '1 day'], array_slice($items[1]['meta'], 0, 2));
	}//end testOfferedCoursesAndTheirDaysToCome()

	/**
	 * Lessons of another cohort never count; three weekdays read as a list;
	 * no offered course reads nothing else.
	 *
	 * @return void
	 */
	public function testWeekdaysAndForeignLessons(): void {
		$rows = [
			'course'  => [['id' => 'c-1', 'name' => 'Week', 'lifecycle' => 'published', 'level' => 'corporate']],
			'cohort'  => [['id' => 'k-1', 'courseId' => 'c-1', 'lifecycle' => 'active']],
			'session' => [
				self::lesson('k-1', '2026-10-07T09:00:00+02:00'),
				self::lesson('k-1', '2026-10-05T09:00:00+02:00'),
				self::lesson('k-1', '2026-10-12T09:00:00+02:00'),
				self::lesson('k-1', '2026-10-06T09:00:00+02:00'),
				self::lesson('k-other', '2026-10-09T09:00:00+02:00'),
			],
		];

		$items = $this->index($rows)->items(namespace: '', today: self::TODAY);

		self::assertSame(['Maandag, dinsdag and woensdag', '4 days'], array_slice($items[0]['meta'], 0, 2));
		self::assertSame('2026-10-12', $items[0]['endDate']);

		$rows['course'][0]['lifecycle'] = 'draft';
		self::assertSame([], $this->index($rows)->items(namespace: '', today: self::TODAY));
	}//end testWeekdaysAndForeignLessons()
}//end class
