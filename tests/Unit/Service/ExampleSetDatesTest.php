<?php

/**
 * Tests for the per-set date offset (demo-dates-follow-the-load-week).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Service\ExampleSetDates;
use OCA\Learniq\Service\LoadedExampleSets;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stand-in for an OpenRegister object.
 */
class FakeStoredObject {
	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct(public array $data) {
	}//end __construct()

	/**
	 * @return array<string, mixed>
	 */
	public function getObject(): array {
		return $this->data;
	}//end getObject()
}//end class

/**
 * Stand-in for OpenRegister's object service: finds and records saves.
 */
class FakeObjectStore {
	/**
	 * @var array<string, FakeStoredObject>
	 */
	public array $objects = [];

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * @param string $id
	 * @param mixed  ...$rest
	 *
	 * @return FakeStoredObject|null
	 */
	public function find(string $id, mixed ...$rest): ?FakeStoredObject {
		unset($rest);
		return ($this->objects[$id] ?? null);
	}//end find()

	/**
	 * @param array<string, mixed> $object
	 * @param mixed                ...$rest
	 *
	 * @return void
	 */
	public function saveObject(array $object, mixed ...$rest): void {
		$this->saves[] = ['object' => $object] + $rest;
	}//end saveObject()
}//end class

/**
 * The offset each set carries, and the move of what exists.
 *
 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-reload-in-another-week-moves-what-exists-and-a-reload-in-the-same-week-writes-nothing
 */
class ExampleSetDatesTest extends TestCase {

	/**
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The service on a given day, with an in-memory app config.
	 *
	 * @param string          $today  The day.
	 * @param array<int, string> $loaded The sets loaded before.
	 * @param FakeObjectStore|null $store The object store.
	 *
	 * @return ExampleSetDates
	 */
	private function dates(string $today, array $loaded=[], ?FakeObjectStore $store=null): ExampleSetDates {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable($today));
		$sets = $this->createMock(LoadedExampleSets::class);
		$sets->method('all')->willReturn(array_map(static fn (string $id): array => ['id' => $id, 'label' => $id], $loaded));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store ?? new FakeObjectStore());

		return new ExampleSetDates(appConfig: $config, time: $time, loadedSets: $sets, container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end dates()

	/**
	 * Never loaded: no offset; loaded before offsets were kept: the boards' own
	 * dates (0); remembered: that offset, and forgotten after a removal.
	 *
	 * @return void
	 */
	public function testTheAppliedOffset(): void {
		self::assertNull($this->dates('2026-10-22T10:00:00+02:00')->appliedOffset('po'));
		self::assertSame(0, $this->dates('2026-10-22T10:00:00+02:00', ['po'])->appliedOffset('po'));

		$dates = $this->dates('2026-10-22T10:00:00+02:00');
		self::assertSame(14, $dates->currentOffset());
		$dates->remember('po', 14);
		self::assertSame(14, $dates->appliedOffset('po'));
		$dates->forget('po');
		self::assertNull($dates->appliedOffset('po'));
	}//end testTheAppliedOffset()

	/**
	 * Existing objects move by the difference, silently; an object without a
	 * date is not written and a removed one is skipped; zero days touch nothing.
	 *
	 * @return void
	 */
	public function testExistingObjectsMoveAndOnlyThoseWithADate(): void {
		$store = new FakeObjectStore();
		$store->objects['a'] = new FakeStoredObject(['title' => 'Groep 7, maandag 5 oktober 2026', 'startsAt' => '2026-10-05T08:30:00+02:00']);
		$store->objects['b'] = new FakeStoredObject(['name' => 'Groep 7']);
		$self    = ['register' => 'learniq', 'schema' => 'session'];
		$objects = [['uuid' => 'a', '@self' => $self], ['uuid' => 'b', '@self' => $self], ['uuid' => 'gone', '@self' => $self]];

		$dates = $this->dates('2026-10-12T10:00:00+02:00', [], $store);
		self::assertSame(['moved' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0], $dates->moveExisting($objects, 0));
		self::assertSame([], $store->saves);

		self::assertSame(['moved' => 1, 'unchanged' => 1, 'missing' => 1, 'failed' => 0], $dates->moveExisting($objects, 7));
		self::assertCount(1, $store->saves);
		self::assertSame(['title' => 'Groep 7, maandag 12 oktober 2026', 'startsAt' => '2026-10-12T08:30:00+02:00'], $store->saves[0]['object']);
		self::assertSame(['a', true], [$store->saves[0]['uuid'], $store->saves[0]['silent']]);
	}//end testExistingObjectsMoveAndOnlyThoseWithADate()
}//end class
