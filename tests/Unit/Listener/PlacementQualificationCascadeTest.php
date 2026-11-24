<?php

/**
 * Unit tests for PlacementQualificationCascade.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\PlacementQualificationCascade;
use OCA\Learniq\Listener\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Service\Pupil\PupilPageReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A placement write keeps its student's qualification line on her profile.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
 */
class PlacementQualificationCascadeTest extends TestCase {

	/**
	 * The profiles written, as saved.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The stored placements of the student.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $placements = [];

	/**
	 * The stored profile.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $profile = ['id' => 'prof-milan', 'ncUserId' => 'milan', 'fullName' => 'Milan de Groot'];

	/**
	 * @return PlacementQualificationCascade
	 */
	private function cascade(): PlacementQualificationCascade {
		$reader = $this->createMock(originalClassName: PupilPageReader::class);
		$reader->method('profileOf')->willReturnCallback(
			function (string $learnerId): ?array {
				if ($learnerId !== 'milan') {
					return null;
				}

				return $this->profile;
			}
		);
		$reader->method('ofPupil')->willReturnCallback(
			function (string $schema): array {
				if ($schema !== 'bpv-placement') {
					return [];
				}

				return $this->placements;
			}
		);
		$reader->method('idOf')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));

		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return OrEntityFactory::make(data: $object, schema: 'learner-profile');
			}
		);

		return new PlacementQualificationCascade(schemas: TransitionScope::resolver(), reader: $reader, objectService: $objects, logger: new NullLogger());
	}//end cascade()

	/**
	 * A transitioned placement event.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transitioned(string $schema, array $object): ObjectTransitionedEvent {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($object);
		$event = $this->createMock(originalClassName: ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn($schema);

		return $event;
	}//end transitioned()

	/**
	 * Milan's placement becomes active: his profile gets the line once; a second write changes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function testARunningPlacementWritesTheLineOnce(): void {
		$this->placements = [
			['id' => 'pl-1', 'learnerId' => 'milan', 'lifecycle' => 'active', 'qualificationName' => 'Monteur mechatronica', 'crebo' => '25290'],
		];

		$this->cascade()->handle($this->transitioned(schema: 'bpv-placement', object: $this->placements[0]));

		self::assertCount(expectedCount: 1, haystack: $this->saved);
		self::assertSame(expected: 'Monteur mechatronica (crebo 25290)', actual: $this->saved[0]['qualificationLabel']);
		self::assertSame(expected: 'Milan de Groot', actual: $this->saved[0]['fullName']);
		self::assertArrayNotHasKey(key: 'id', array: $this->saved[0]);

		$this->profile['qualificationLabel'] = 'Monteur mechatronica (crebo 25290)';
		self::assertFalse(condition: $this->cascade()->restamp(learnerId: 'milan'));
	}//end testARunningPlacementWritesTheLineOnce()

	/**
	 * A finished placement clears the line; created and updated placements count too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function testAFinishedPlacementClearsTheLine(): void {
		$this->profile['qualificationLabel'] = 'Monteur mechatronica (crebo 25290)';
		$this->placements = [['id' => 'pl-1', 'learnerId' => 'milan', 'lifecycle' => 'completed', 'crebo' => '25290']];

		$updated = $this->createMock(originalClassName: ObjectUpdatedEvent::class);
		$updated->method('getNewObject')->willReturn(OrEntityFactory::make(data: $this->placements[0], schema: 'bpv-placement'));
		$this->cascade()->handle($updated);

		self::assertNull(actual: $this->saved[0]['qualificationLabel']);

		$this->saved = [];
		$this->placements[0]['lifecycle'] = 'active';
		$created = $this->createMock(originalClassName: ObjectCreatedEvent::class);
		$created->method('getObject')->willReturn(OrEntityFactory::make(data: $this->placements[0], schema: 'bpv-placement'));
		$this->cascade()->handle($created);
		self::assertSame(expected: 'crebo 25290', actual: $this->saved[0]['qualificationLabel']);
	}//end testAFinishedPlacementClearsTheLine()

	/**
	 * Another schema, a student without a profile, a failed save: nothing written, nothing thrown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function testWhatTheCascadeLeavesAlone(): void {
		$this->cascade()->handle($this->transitioned(schema: 'bpv-visit', object: ['learnerId' => 'milan']));
		$this->cascade()->handle($this->transitioned(schema: 'bpv-placement', object: ['learnerId' => 'nobody']));
		$this->cascade()->handle($this->transitioned(schema: 'bpv-placement', object: []));
		self::assertSame(expected: [], actual: $this->saved);

		$this->placements = [['lifecycle' => 'active', 'crebo' => '25290']];
		$reader = $this->createMock(originalClassName: PupilPageReader::class);
		$reader->method('profileOf')->willThrowException(new RuntimeException('down'));
		$cascade = new PlacementQualificationCascade(
			schemas: TransitionScope::resolver(),
			reader: $reader,
			objectService: $this->createMock(originalClassName: ObjectService::class),
			logger: new NullLogger()
		);
		self::assertFalse(condition: $cascade->restamp(learnerId: 'milan'));
	}//end testWhatTheCascadeLeavesAlone()

	/**
	 * The cascade listens on created, updated and transitioned objects.
	 *
	 * @return void
	 */
	public function testTheCascadeIsRegistered(): void {
		$pairs   = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event.' => '.$listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectTransitionedEvent::class] as $event) {
			self::assertContains(needle: $event.' => '.PlacementQualificationCascade::class, haystack: $pairs);
		}
	}//end testTheCascadeIsRegistered()
}//end class
