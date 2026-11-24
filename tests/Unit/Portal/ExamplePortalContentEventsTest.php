<?php

/**
 * Learniq ExamplePortalContent event tests
 *
 * Pins how a set's declared events are written once, matched by title, how a
 * news item that names an event by title gets its id as `eventRef` and never
 * the `event` key, and how a reload in another week moves a stored event.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/example-set-declares-its-events/specs/example-sets/spec.md#requirement-a-set-may-declare-events-and-a-news-item-may-name-one
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExamplePortalContent;
use OCA\Learniq\Service\DemoDates;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * ExamplePortalContent::events(), the news item's event and the date move.
 */
#[CoversClass(ExamplePortalContent::class)]
#[UsesClass(DemoDates::class)]
class ExamplePortalContentEventsTest extends TestCase {

	/**
	 * The saves the store took: schema, uuid, object.
	 *
	 * @var array<int, array{0: string, 1: string|null, 2: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * A content writer over a store that holds `$stored` per schema and
	 * answers a save with the id `new-<n>`.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $stored The stored rows per schema.
	 *
	 * @return ExamplePortalContent
	 */
	private function content(array $stored): ExamplePortalContent {
		$store = $this->createMock(originalClassName: ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			static function (array $config) use (&$stored): array {
				return ($stored[$config['filters']['schema']] ?? []);
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options=new ObjectSaveOptions()) use (&$stored): ObjectEntity {
				$schema = (string)$options->target->schema;
				$uuid   = ($options->uuid ?? 'new-' . (count($this->saves) + 1));
				$this->saves[]     = [$schema, $options->uuid, $object];
				$stored[$schema][] = ['@self' => ['id' => $uuid]] + $object;
				return OrEntityFactory::make(data: ['id' => $uuid], schema: $schema, register: 'portaliq', uuid: $uuid);
			}
		);

		return new ExamplePortalContent(objectService: $store);
	}//end content()

	/**
	 * The vo shape: one event and the news item that names it.
	 *
	 * @return array<string, mixed>
	 */
	private static function declaration(): array {
		return [
			'portal' => ['slug' => 'vaartveld'],
			'events' => [
				[
					'title'          => 'Informatieavond profielkeuze, klas 3',
					'start'          => '2026-11-03T19:30:00+01:00',
					'target'         => ['schoolRef' => 'school-1'],
					'rsvpBy'         => ['guardian', 'learner'],
					'askSeats'       => true,
					'signupDeadline' => '2026-10-30',
				],
			],
			'news'   => [
				[
					'title'  => 'Informatieavond profielkeuze op dinsdag 3 november',
					'body'   => 'Op deze avond hoor je hoe het kiezen gaat.',
					'event'  => 'Informatieavond profielkeuze, klas 3',
					'target' => ['schoolRef' => 'school-1'],
				],
			],
		];
	}//end declaration()

	/**
	 * A first load writes the event published, then the news item with the event's id.
	 *
	 * @return void
	 */
	public function testAFirstLoadWritesTheEventAndTheNewsItemNamesIt(): void {
		$content = $this->content(stored: []);

		self::assertSame(expected: ['created' => 1, 'kept' => 0], actual: $content->events(declaration: self::declaration()));
		self::assertSame(expected: ['created' => 1, 'kept' => 0], actual: $content->news(declaration: self::declaration()));

		[$eventSchema, $eventUuid, $event] = $this->saves[0];
		self::assertSame(expected: ['schoolEvent', null], actual: [$eventSchema, $eventUuid]);
		self::assertSame(expected: 'published', actual: $event['status']);
		self::assertSame(expected: ['guardian', 'learner'], actual: $event['rsvpBy']);

		[$newsSchema, , $news] = $this->saves[1];
		self::assertSame(expected: 'newsItem', actual: $newsSchema);
		self::assertSame(expected: 'new-1', actual: $news['eventRef']);
		self::assertArrayNotHasKey(key: 'event', array: $news);
		self::assertSame(expected: ['published', 'vaartveld'], actual: [$news['status'], $news['portal']]);
	}//end testAFirstLoadWritesTheEventAndTheNewsItemNamesIt()

	/**
	 * A second load writes no second event and reports it kept.
	 *
	 * @return void
	 */
	public function testASecondLoadKeepsTheEvent(): void {
		$content = $this->content(stored: ['schoolEvent' => [['@self' => ['id' => 'ev-1'], 'title' => 'Informatieavond profielkeuze, klas 3']]]);

		self::assertSame(expected: ['created' => 0, 'kept' => 1], actual: $content->events(declaration: self::declaration()));
		self::assertSame(expected: [], actual: $this->saves);
	}//end testASecondLoadKeepsTheEvent()

	/**
	 * A news item that names an event the portal does not have gets no reference and no `event` key.
	 *
	 * @return void
	 */
	public function testANewsItemNamingAMissingEventIsWrittenWithoutAReference(): void {
		$content = $this->content(stored: []);
		$content->news(declaration: self::declaration());

		self::assertCount(expectedCount: 1, haystack: $this->saves);
		self::assertArrayNotHasKey(key: 'eventRef', array: $this->saves[0][2]);
		self::assertArrayNotHasKey(key: 'event', array: $this->saves[0][2]);
	}//end testANewsItemNamingAMissingEventIsWrittenWithoutAReference()

	/**
	 * A set without events reads nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testASetWithoutEventsTouchesNothing(): void {
		$store = $this->createMock(originalClassName: ObjectService::class);
		$store->expects(self::never())->method('findAll');
		$store->expects(self::never())->method('saveObject');

		$content = new ExamplePortalContent(objectService: $store);
		self::assertSame(expected: ['created' => 0, 'kept' => 0], actual: $content->events(declaration: ['portal' => ['slug' => 'vaartveld']]));
	}//end testASetWithoutEventsTouchesNothing()

	/**
	 * The declared events' dates move with the load week, and a reload in
	 * another week moves the stored event found by its title.
	 *
	 * @return void
	 */
	public function testTheEventsDatesMoveWithTheLoadWeek(): void {
		$stored  = ['@self' => ['id' => 'ev-1'], 'title' => 'Informatieavond profielkeuze, klas 3', 'start' => '2026-11-10T19:30:00+01:00'];
		$content = $this->content(stored: ['schoolEvent' => [$stored]]);

		$dated = $content->dated(declaration: self::declaration(), days: 7);
		self::assertSame(expected: '2026-11-10T19:30:00+01:00', actual: $dated['events'][0]['start']);

		$written = $content->moveDates(declaration: self::declaration(), refs: ['vaartveld'], previous: 7, days: 7);
		self::assertSame(expected: 1, actual: $written);
		self::assertSame(expected: ['schoolEvent', 'ev-1'], actual: [$this->saves[0][0], $this->saves[0][1]]);
		self::assertSame(expected: '2026-11-17T19:30:00+01:00', actual: $this->saves[0][2]['start']);
	}//end testTheEventsDatesMoveWithTheLoadWeek()
}//end class
