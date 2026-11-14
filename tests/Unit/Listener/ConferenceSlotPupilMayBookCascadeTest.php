<?php

/**
 * Learniq ConferenceSlotPupilMayBookCascade unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-may-book-her-own-mentor-talk-when-the-round-allows-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\ConferenceSlotPupilMayBookCascade;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A round's changed pupilMayBook reaches its times; nothing else moves them.
 */
#[CoversClass(ConferenceSlotPupilMayBookCascade::class)]
class ConferenceSlotPupilMayBookCascadeTest extends TestCase {

	/**
	 * The saves made.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The listener over these stored times.
	 *
	 * @param array<int, array<string, mixed>> $slots  The times findAll returns.
	 * @param bool                             $throws Whether the read throws.
	 *
	 * @return ConferenceSlotPupilMayBookCascade
	 */
	private function cascade(array $slots, bool $throws=false): ConferenceSlotPupilMayBookCascade {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturnCallback(static fn ($entity) => $entity->getSchema());
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($slots, $throws): array {
				if ($throws === true) {
					throw new RuntimeException('gone');
				}

				return ($config['filters']['schema'] === 'conference-slot') ? $slots : [];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null, bool $_rbac=true) {
				$this->saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac];
				return null;
			}
		);

		return new ConferenceSlotPupilMayBookCascade(schemas: $resolver, objects: $objects, logger: new NullLogger());
	}//end cascade()

	/**
	 * A round update.
	 *
	 * @param bool|null $before The old value, null for none.
	 * @param bool|null $after  The new value, null for none.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function update(?bool $before, ?bool $after): ObjectUpdatedEvent {
		$old = array_filter(['name' => 'Mentorgesprekken H4b', 'pupilMayBook' => $before], static fn ($v) => $v !== null);
		$new = array_filter(['name' => 'Mentorgesprekken H4b', 'pupilMayBook' => $after], static fn ($v) => $v !== null);

		return new ObjectUpdatedEvent(
			OrEntityFactory::make($new, 'conference-round', uuid: 'round-1'),
			OrEntityFactory::make($old, 'conference-round', uuid: 'round-1')
		);
	}//end update()

	/**
	 * Switched on, every time of the round that lacks it gets it, without
	 * RBAC; a time already marked and a time of another round are untouched.
	 *
	 * @return void
	 */
	public function testSwitchingOnMarksTheRoundsTimes(): void {
		$slots = [
			['id' => 's1', 'conferenceRoundId' => 'round-1', 'lifecycle' => 'free'],
			['id' => 's2', 'conferenceRoundId' => 'round-1', 'lifecycle' => 'free', 'pupilMayBook' => true],
			['id' => 's3', 'conferenceRoundId' => 'round-2', 'lifecycle' => 'free'],
		];
		$this->cascade(slots: $slots)->handle($this->update(before: null, after: true));

		self::assertCount(1, $this->saved);
		self::assertSame(['id' => 's1', 'conferenceRoundId' => 'round-1', 'lifecycle' => 'free', 'pupilMayBook' => true], $this->saved[0]['object']);
		self::assertSame(['conference-slot', 's1', false], [$this->saved[0]['schema'], $this->saved[0]['uuid'], $this->saved[0]['_rbac']]);
	}//end testSwitchingOnMarksTheRoundsTimes()

	/**
	 * Switched off, a marked time is unmarked; no change, a create, another
	 * schema or a failing read writes nothing and throws nothing.
	 *
	 * @return void
	 */
	public function testSwitchingOffUnmarksAndNothingElseMovesTheTimes(): void {
		$marked = [['id' => 's1', 'conferenceRoundId' => 'round-1', 'pupilMayBook' => true]];
		$this->cascade(slots: $marked)->handle($this->update(before: true, after: false));
		self::assertCount(1, $this->saved);
		self::assertFalse($this->saved[0]['object']['pupilMayBook']);

		$this->cascade(slots: $marked)->handle($this->update(before: true, after: true));
		$this->cascade(slots: $marked)->handle($this->update(before: null, after: false));
		$this->cascade(slots: $marked)->handle(new ObjectCreatedEvent(OrEntityFactory::make(['pupilMayBook' => true], 'conference-round', uuid: 'round-1')));
		$this->cascade(slots: $marked)->handle(
			new ObjectUpdatedEvent(OrEntityFactory::make(['pupilMayBook' => false], 'conference-slot', uuid: 's1'), OrEntityFactory::make(['pupilMayBook' => true], 'conference-slot', uuid: 's1'))
		);
		$this->cascade(slots: $marked, throws: true)->handle($this->update(before: false, after: true));
		self::assertCount(1, $this->saved);
	}//end testSwitchingOffUnmarksAndNothingElseMovesTheTimes()
}//end class
