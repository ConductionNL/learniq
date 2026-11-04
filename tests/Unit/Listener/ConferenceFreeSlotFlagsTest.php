<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTimeZone;
use OCA\Learniq\Listener\ConferenceFreeSlotGenerator;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * What the generator asks OpenRegister (the cohort read and the slot write run
 * without the caller's RBAC and tenancy), and two edges of the times it writes.
 *
 * @covers \OCA\Learniq\Listener\ConferenceFreeSlotGenerator
 * @uses   \OCA\Learniq\Service\ConferenceSlotSlicer
 */
class ConferenceFreeSlotFlagsTest extends TestCase {

	/**
	 * The flags of each cohort read, by parameter name.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $reads = [];

	/**
	 * The arguments of each slot write, by parameter name.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $writes = [];

	/**
	 * The cohort read and the slot write both skip RBAC and tenancy.
	 *
	 * @return void
	 */
	public function testCohortReadAndSlotWriteSkipRbacAndTenancy(): void {
		$blocks = [['startsAt' => '2026-10-08T18:00:00+02:00', 'endsAt' => '2026-10-08T18:10:00+02:00']];

		self::assertSame(1, $this->generator(blocks: $blocks)->generateForRound(round: $this->round()));

		self::assertCount(1, $this->reads);
		self::assertFalse($this->reads[0]['_rbac']);
		self::assertFalse($this->reads[0]['_multitenancy']);
		self::assertCount(1, $this->writes);
		self::assertFalse($this->writes[0]['_rbac']);
		self::assertFalse($this->writes[0]['_multitenancy']);
	}//end testCohortReadAndSlotWriteSkipRbacAndTenancy()

	/**
	 * Two overlapping blocks of one availability give each time once.
	 *
	 * @return void
	 */
	public function testOverlappingBlocksOfOneAvailabilityWriteEachTimeOnce(): void {
		$blocks = [
			['startsAt' => '2026-10-08T18:00:00+02:00', 'endsAt' => '2026-10-08T18:20:00+02:00'],
			['startsAt' => '2026-10-08T18:10:00+02:00', 'endsAt' => '2026-10-08T18:30:00+02:00'],
		];

		self::assertSame(3, $this->generator(blocks: $blocks)->generateForRound(round: $this->round()));

		self::assertSame(
			['2026-10-08T18:00:00+02:00', '2026-10-08T18:10:00+02:00', '2026-10-08T18:20:00+02:00'],
			array_map(static fn (array $write): string => $write['object']['startsAt'], $this->writes)
		);
	}//end testOverlappingBlocksOfOneAvailabilityWriteEachTimeOnce()

	/**
	 * A time entered with an offset other than Amsterdam's keeps its wall clock in the label.
	 *
	 * @return void
	 */
	public function testLabelKeepsATimeEnteredWithAnotherOffset(): void {
		$blocks = [['startsAt' => '2026-10-08T18:00:00+05:00', 'endsAt' => '2026-10-08T18:10:00+05:00']];

		$this->generator(blocks: $blocks)->generateForRound(round: $this->round());

		self::assertSame('08-10-2026 18:00-18:10, Jan', $this->writes[0]['object']['slotLabel']);
	}//end testLabelKeepsATimeEnteredWithAnotherOffset()

	/**
	 * A round of one group.
	 *
	 * @return array<string,mixed>
	 */
	private function round(): array {
		return ['id' => 'r-1', 'tenant_id' => 't-1', 'cohortIds' => ['c-1'], 'invitedLearnerRefs' => ['ref-a']];
	}//end round()

	/**
	 * The generator over one teacher's availability, recording the cohort reads and slot writes.
	 *
	 * @param array<int,array<string,string>> $blocks The availability's blocks.
	 *
	 * @return ConferenceFreeSlotGenerator
	 */
	private function generator(array $blocks): ConferenceFreeSlotGenerator {
		$availability = ['teacherId' => 'jan', 'lifecycle' => 'submitted', 'blocks' => $blocks];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($availability): array {
				$filters = $config['filters'];
				$isSubmitted = $filters['schema'] === 'teacher-availability' && $filters['lifecycle'] === 'submitted';
				return $isSubmitted === true ? [$availability] : [];
			}
		);
		$objects->method('find')->willReturnCallback(
			function (...$args) {
				$this->reads[] = $this->byName(method: 'find', args: $args);
				return OrEntityFactory::make(['teacherIds' => ['jan'], 'learnerIds' => ['a']], 'cohort');
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) {
				$this->writes[] = $this->byName(method: 'saveObject', args: $args);
				return OrEntityFactory::make(['id' => 'saved'], 'conference-slot');
			}
		);
		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('resolveInTenant')->willReturn('ref-a');
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturn('Jan');
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		return new ConferenceFreeSlotGenerator($objects, $this->createMock(ListenerSchemaResolver::class), $profiles, $users, $zone, new NullLogger());
	}//end generator()

	/**
	 * A mock's positional arguments by the parameter names of the method it stands in for.
	 *
	 * @param string            $method The ObjectService method.
	 * @param array<int,mixed>  $args   The arguments.
	 *
	 * @return array<string,mixed>
	 */
	private function byName(string $method, array $args): array {
		$named = [];
		foreach ((new ReflectionMethod(ObjectService::class, $method))->getParameters() as $index => $parameter) {
			if (array_key_exists($index, $args) === true) {
				$named[$parameter->getName()] = $args[$index];
			}
		}

		return $named;
	}//end byName()
}//end class
